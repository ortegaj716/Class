<?php
/**
 * @file
 * Allocator Class
 *
 * @package groupgrade
 * @subpackage allocation
 */
namespace Drupal\ClassLearning\Workflow;

use Drupal\ClassLearning\Exception as AllocatorException,
  Drupal\ClassLearning\Models\WorkflowTask;

/**
 * User Allocator
 *
 * Used to assign a pool of users to specific roles inside of a work flow.
 * See previous work {@link http://web.njit.edu/~mt85/UsersAlg.php}
 *
 * To run an intelligent version of the allocator, you should check out
 * the {@link Allocator::assignmentRun()} method. It will run the allocation
 * a few times to ensure there are no errors.
 * 
 * @license MIT
 * @package groupgrade
 * @subpackage allocation
 */
class Allocator {
  /**
   * Workflow Storage
   *
   * @see Allocator::getWorkflows()
   * @see Allocator::addWorkflow()
   * @var array
   */
  protected $workflows = [];

  /**
   * Roles Storage
   *
   * To access, use `getRoles()`
   * 
   * @var array
   */
  protected $roles = [];

  /**
   * @ignore
   */
  protected $roles_rules = [];

  /**
   * Pools of users
   * 
   * A one level array:
   * <code>
   * [
   *   'UserObject',
   *   'UserObject',
   *   ...
   * ]
   * </code>
   * 
   * @var array
   * @see Allocator::getPools()
   */
  protected $pools = [];

  /**
   * Temporary storage for users being added to roles
   *
   * @access private
   * @var array
   */
  protected $roles_queue = [];

  /**
   * Use to track the number of runs the algorithm has run
   * No beneficial use
   * 
   * @var integer
   */
  public $runCount = 0;

  /**
   * A two-dimensional array of storage for keeping the storage
   * of the task instance id's relative to the workflow->role ID
   *
   * The array looks like this:
   * 
   * ```
   * [
   *   'workflow_id' => [
   *     'internal role id' => 'task instance id',
   *     'internal role id' => 'task instance id',
   *     'internal role id' => 'task instance id'    
   *   ],
   *   'workflow_id' => [
   *     'internal role id' => 'task instance id',
   *     'internal role id' => 'task instance id'
   *   ],
   *   ...
   * ]
   * @global array
   */
  protected $taskInstanceStorage = [];

  /**
   * Construct the Allocator Algorithm
   *
   * @return void
   */
  public function __construct()
  {

  }

  /**
   * Grunt work to assign users
   *
   * It'd be best to run `assignmentRun()` as that method automatically detects errors
   * and fixes them. This is a helper processor.
   * 
   * @return void
   */
  public function runAssignment()
  {
    if (count($this->roles) == 0)
      throw new AllocatorException('Roles are not defined for allocation.');

    if (count($this->pools) == 0)
      throw new AllocatorException('Pools are not defined for allocation.');
    
    // Reset it
    $this->resetWorkflows();

    if (count($this->workflows) == 0)
      throw new AllocatorException('No workflows to allocate to.');

    // Now let's find the assignes
    foreach($this->roles as $role_id => $role_data) :
      $rolePool = $this->getPool($role_data['rules']['pool']['name'])->all();

      // Let's keep this very random
      $rolePool = shuffle_assoc($rolePool);

      // Add it to a queue
      $this->roles_queue[$role_id] = $rolePool;
    endforeach;

    // Go though the workflows
    foreach($this->workflows as $workflow_id => $workflow) :
      // Loop though each role inside of the workflow
      // 
      // Loop though all the users in the queue
      // 
      // Can join: assign and remove from queue
      // Can't join: point to next user in queue
      foreach($workflow as $role_id => $ignore) :
        // Just check if it's already assigned to be sure
        if ($ignore !== NULL)
          throw new AllocatorException(sprintf('Workflow role %d is already assigned to %d', $role_id, $ignore));
        
        $currentRole = $this->roles[$role_id];

        // If they aren't pulling from a list, they're going to be taking random items from a list
        if (! $currentRole['rules']['pool']['pull after']) :
          $this->roles_queue[$role_id] = shuffle_assoc($this->roles_queue[$role_id]);
        endif;

        // See if the task instance has a workflow alias
        if ($this->workflowTaskHasAlias($role_id, $currentRole, $this->workflows[$workflow_id]))
        {
          // Assign the user based upon the task alias
          $this->workflows[$workflow_id][$role_id] = $this->assignTaskAlias($role_id, $currentRole, $this->workflows[$workflow_id]);
        } else {
          // Start from the beginning of the queue
          foreach($this->roles_queue[$role_id] as $queue_id => $user) :
            // They're not a match -- skip to the next user in queue
            if ($this->canEnterWorkflow($user, $this->workflows[$workflow_id], $role_id))
            {
              $this->workflows[$workflow_id][$role_id] = $user;

              // Should they be removed from the pool?
              if ($currentRole['rules']['pool']['pull after'])
                unset($this->roles_queue[$role_id][$queue_id]);

              break;
            }
          endforeach;
        }
        endforeach;
      endforeach;
  }

  /**
   * Identify if a user can enter a specific workflow
   *
   * Helper function to see if a user is already in a
   * workflow (cannot join then).
   * 
   * @param SectionUser User Object
   * @param array Workflow to check for entry
   * @param integer The Role ID to check
   * @return bool
   */
  protected function canEnterWorkflow($user, $workflow, $role_id)
  {
    $role = $this->roles[$role_id];

    foreach($workflow as $workflow_role_id => $assigne)
    {
      // Workflow Instance not assigned
      if ($assigne == NULL)
        continue;

      if ((int) $assigne->user_id == (int) $user->user_id)
      {
        // They've got a match! Now let's see if it's not an alias
        $workflowRole = $this->roles[$workflow_role_id];

        // The role they're trying to enter is an alias to a role that was already taken by
        // the user. A false positive.
        if ( isset($workflowRole['rules']['user alias']) AND $workflowRole['rules']['user alias'] == $role['name'])
          continue;
        else
          return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Check for a User Alias of a workflow task
   * 
   * See if a task instance has a task alias
   * If it has a task alias, then furthur test to see if the alias task is
   * also assigned to a user already. Return true if it's already assigned.
   * 
   * @return bool
   * @param integer $role_id The Role ID
   * @param array $role The data pertaining to the role
   * @param array $workflow The workflow data
   */
  public function workflowTaskHasAlias($role_id, $role, &$workflow)
  {
    $findAlias = $this->findWorkflowTaskAlias($role_id, $workflow);

    if (is_array($findAlias) AND $workflow[$findAlias['role id']] !== NULL)
      return TRUE;
    else
      return FALSE;
  }

  /**
   * Assign the user based upon the task alias
   *
   * The function will assume you've already checked to see if there
   * actually is a task alias via {@link Allocator::workflowTaskHasAlias()}
   *
   * @param integer $role_id The Role ID
   * @param array $role The data pertaining to the role
   * @param array $workflow The workflow data
   * @return integer
   */
  public function assignTaskAlias($role_id, $role, &$workflow)
  {
    $findAlias = $this->findWorkflowTaskAlias($role_id, $workflow);

    if (! $findAlias  OR $workflow[$findAlias['role id']] == NULL)
      throw new AllocatorException('Alias is actually not assigned. Please run Allocator::workflowTaskHasAlias() first.');

    return $workflow[$findAlias['role id']];
  }

  /**
   * Helper function to determine the workflow task alias
   *
   * @access protected
   * @return array|bool
   */
  protected function findWorkflowTaskAlias($role_id, &$workflow)
  {
    $role = $this->roles[$role_id];

    if (! isset($role['rules']['user alias'])) return false;

    // It has one -- let's find the other task instance
    $aliasRole = $role['rules']['user alias'];

    foreach ($this->roles as $sub_role_id => $sub_role_data)
    {
      if ($sub_role_data['name'] !== $aliasRole)
        continue;

      // Check for a double alias!
      // This is set on the instance that has a count > 1
      if (
        isset( $role['rules']['user alias all types'] )
      AND
        $role['rules']['user alias all types']
      AND
        isset ($role['rules']['count'])
      AND
        (int) $role['rules']['count'] > 1
      ) :
        // They have double alias protection on
        // Now we have to find the other instance of this role.
        $count = $role['rules']['count']-1;

        // Go up and down the workflow in the amount of instances they
        // have minus 1.
        
        // Search DOWN
        for ($i = $role_id-1; $i > $role_id-1-$count; $i--)
        {
          // Gone too far!
          if ($i < 0) break;

          // This instance isn't assigned
          if ($workflow[$i] !== NULL AND $this->roles[$i]['name'] == $role['name'])
            return FALSE;
        }

        // Search UP
        for ($i = $role_id+1; $i < $role_id+1+$count; $i++)
        {
          // No longer found.
          if (! isset($workflow[$i]))
            break;

          // This instance isn't assigned
          if ($workflow[$i] !== NULL AND $this->roles[$i]['name'] == $role['name'])
            return FALSE;
        }
      endif;

      // We've got a match!
      // Check to make sure we're not gonna 500
      if ($sub_role_id == $role_id)
        throw new AllocatorException(sprintf('Alias role ID is the same as parent role ID: %d %d', $aliasRoleId, $role_id));

      return [
        'role id' => $sub_role_id,
        'role data' => $sub_role_data,
      ];
      break;
    }

    // No match found.
    return FALSE;
  }
  
  /**
   * Does a workflow contain a duplicate error?
   *
   * @param array $workflow Workflow storage array
   * @return bool
   */
  public function contains_error($workflow)
  {
    // Check if it contains unassigned users
    foreach ($workflow as $role => $user) :
      if ($user === NULL) return TRUE;
    endforeach;

    return FALSE;
  }

  /**
   * See if an array of workflows contains any errors
   *
   * @return bool
   */
  public function contains_errors($workflows)
  {
    foreach($workflows as $workflow) :
      if ($this->contains_error($workflow) ) return TRUE;
    endforeach;

    return FALSE;
  }

  /**
   * Empty Workflow
   * The default values for a workflow
   *
   * @return array
   */
  public function emptyWorkflow($workflow_id)
  {
    // Let's get the tasks for this workflow
    $tasks = WorkflowTask::where('workflow_id', '=', $workflow_id)
      ->get();

    // Setup the instance storage for this workflow
    $this->taskInstanceStorage[$workflow_id] = $usedInstances = [];

    $i = [];
    foreach($this->roles as $role_id => $role_data) :
      // Determine which task instance this is
      $taskInstanceId = 0;
      foreach ($tasks as $task) :
        // It's a match
        if ($task->type == $role_data['name'] AND ! in_array($task->task_id, $usedInstances))
        {
          $taskInstanceId = $task->task_id;
          $usedInstances[] = $task->task_id;
          break;
        }
      endforeach;

      if ($taskInstanceId == 0)
        throw new AllocatorException(
          sprintf('Unknown task instance id to assign for role %s of workflow %d', $role_data['name'], $workflow_id)
        );
      else
        $this->taskInstanceStorage[$workflow_id][$role_id] = $taskInstanceId;

      // Add this to the tempory storage
      $i[$role_id] = NULL;
    endforeach;

    return $i;
  }

  /**
   * Reset all the workflows
   *
   * @access protected
   */
  protected function resetWorkflows()
  {
    // Clear the instance storage
    $this->taskInstanceStorage = [];

    foreach ($this->workflows as $workflow_id => $workflow) 
      $this->workflows[$workflow_id] = $this->emptyWorkflow($workflow_id);
  }

  /**
   * Add a user role (problem creator, solver, etc)
   *
   * @param string Name of the role
   * @param array
   */
  public function createRole($name, $rules = [])
  {
    if (! isset($rules['pool']))
      $rules['pool'] = $this->defaultRolePool();
    else
      $rules['pool'] = array_merge($this->defaultRolePool(), $rules['pool']);

    $this->roles[] = [
      'name' => $name,
      'rules' => (array) $rules,
    ];
  }

  /**
   * Get the Workflows
   *
   * @return array
   */
  public function getWorkflows()
  {
    return $this->workflows;
  }

  /**
   * Get a Specific Workflow
   *
   * @param integer
   * @return array|void
   */
  public function getWorkflow(int $workflow_id)
  {
    return (isset($this->workflows[$workflow_id])) ? $this->workflows[$workflow_id] : NULL;
  }

  /**
   * Get the task instance storage
   *
   * This is the associative IDs to associate the internal role ID inside of
   * the {@link Allocator::getWorkflow()} data to the task instance ID from the workflow.
   * 
   * @return array
   */
  public function getTaskInstanceStorage()
  {
    return $this->taskInstanceStorage;
  }

  /**
   * Add a workflow
   *
   * This should be called **after** registering all the roles for the allocation
   *
   * @param int
   */
  public function addWorkflow($workflow_id)
  {
    $this->workflows[$workflow_id] = NULL;
  }

  /**
   * Retrieve the Roles
   *
   * @see Allocator::createRole()
   * @return array
   */
  public function getRoles()
  {
    return $this->roles;
  }

  /**
   * Add a pool of users
   *
   * @param string $name The name of the pool
   * @param Illuminate\Container\Container $users Users of the pool
   *   which are just a database record from SectionUsers
   */
  public function addPool($name, $users)
  {
    $this->pools[$name] = $users;
  }

  /**
   * Retrieve the pools of users
   *
   * @return array
   */
  public function getPools()
  {
    return $this->pools;
  }

  /**
   * Retrieve a Full Pool
   *
   * @param string Pool Name
   */
  public function getPool($name)
  {
    return (isset($this->pools[$name])) ? $this->pools[$name] : NULL;
  }

  /**
   * Inteligently run the sorting algorithm
   *
   * We run it for however much $maxRuns is set to to ensure we get the
   * least amount of errors.
   *
   * You need to take the return of this method and use that and not the
   * object that was used to call it.
   * 
   * @todo If cannot find one w/o errors, return one with least
   * @param integer Max runs
   * @return object Allocator Object
   */
  public function assignmentRun($maxRuns = 20)
  {
    $index = [];
    $errorIndex = [];
    $runCount = 0;

    for ($i = 0; $i < $maxRuns; $i++) :
      $this->runCount++;

      $this->runAssignment();

      $hasErrors = $this->contains_errors($this->getWorkflows());

      if (! $hasErrors)
        return $this;
    endfor;

    return $this;
  }

  /**
   * Default Pool Settings for a role
   *
   * @return array
   */
  public function defaultRolePool()
  {
    return [
      'name' => 'student',
      'pull after' => true,
    ];
  }

  /**
   * Dump the details of the allocation
   *
   * Used to debug the allocation
   * 
   * @return void
   */
  public function dump()
  {
    ?>
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.1/jquery.min.js"></script>
<script type="text/javascript">
  $(document).ready(function()
{
  console.log('ready');
  $('table td').click(function() {
    name = $(this).text();
    
    // Remove the previous ones
    $('table td[bgcolor="green"]').removeAttr('bgcolor')

    $('table td').each(function()
    {
      if ($(this).text() == name) {
        $(this).attr('bgcolor', 'green');
      }
    });
  });
});
</script>
<table width="100%" border="1">
  <thead>
    <tr>
      <?php foreach($this->roles as $role_id =>$role_data) : ?>
        <th><?php echo $role_data['name']; ?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
    <?php foreach($this->workflows as $user_id => $workflow) : ?>
      <tr <?php /* if ($this->contains_error($workflow)) echo 'bgcolor="orange"'; */ ?>>
        <?php foreach($workflow as $role => $assigne) :
          if ($assigne === NULL) :
            ?><td bgcolor="red">NONE</td><?php
          else :
            $user = user_load($assigne->user_id);
            ?><td><?php echo $user->name; ?></td><?php
          endif;
        endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<!-- Now Show a user's membership table -->
<p>&nbsp;</p>

<table width="100%" border="1">
  <thead>
    <tr>
      <th>Student</th>

      <?php foreach($this->roles as $role_id => $role_data) :
        if ($role_data['rules']['pool']['name'] !== 'student') continue;
        ?>
        <th>is <?php echo $role_data['name']; ?>?</th>
      <?php endforeach; ?>
    </tr>
  </thead>

  <tbody>
    <?php foreach($this->pools['student'] as $user) : ?>
    <tr>
      <td><?php echo user_load($user->user_id)->name; ?></td>

    <?php foreach($this->roles as $role_id => $role_data) :

    if ($role_data['rules']['pool']['name'] !== 'student') continue;

    $found = false; ?>
      <?php
      foreach($this->workflows as $workflow) :
        if ($workflow[$role_id] !== NULL AND $workflow[$role_id]->user_id == $user->user_id) :
          ?><td bgcolor="blue">YES</td><?php
        $found = true;
        endif;
      endforeach;
      if (! $found) : ?>
          <td bgcolor="red">NO</td>
        <?php endif;
    endforeach; endforeach; ?>
  </tr>
  </tbody>
</table>

<p><strong>Total Students:</strong> <?php echo count($this->pools['student']); ?></p>
<p><strong>Total Runs:</strong> <?php echo $this->runCount; ?></p>
<pre>
</pre>
<?php
  }
}
