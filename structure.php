<?php if (!defined('APPLICATION')) {
    exit();
}
use GroupModel;

if (!isset($Drop)) {
    $Drop = false;
}

if (!isset($Explicit)) {
    $Explicit = false;
}

$Database = Gdn::database();
$SQL = $Database->sql();

$Construct = $Database->structure();
$Px = $Database->DatabasePrefix;

//
// Custom tables
//

// Role Table
$Construct->table('Group');
$GroupTableExists = $Construct->tableExists();

if (!$GroupTableExists) {
    $Construct
        ->primaryKey('GroupID')
        ->column('Name', 'varchar(100)')
        ->column('Description', 'varchar(500)', true)
        ->column('Type', [GroupModel::TYPE_PUBLIC, GroupModel::TYPE_PRIVATE, GroupModel::TYPE_SECRET], true)
        ->column('Deletable', 'tinyint(1)', '1')
        ->column('Closed', 'tinyint(1)', '0')
        ->column('DateInserted', 'datetime', false, 'index')
        ->column('Icon', 'varchar(255)', true)
        ->column('Banner', 'varchar(255)', true)
        ->column('OwnerID', 'int', false, 'key')
        ->column('ChallengeID', 'varchar(36)', true )
        ->column('ChallengeUrl', 'varchar(255)', true)
        ->set($Explicit, $Drop);
} else {
    // Updated columns if the table exist. It can be removed after deploying
    $Construct
        ->primaryKey('GroupID')
        ->column('Name', 'varchar(100)')
        ->column('Description', 'varchar(500)', true)
        ->column('Type', [GroupModel::TYPE_PUBLIC, GroupModel::TYPE_PRIVATE, GroupModel::TYPE_SECRET], true)
        ->column('Deletable', 'tinyint(1)', '1')
        ->column('Closed', 'tinyint(1)', '0')
        ->column('DateInserted', 'datetime', false, 'index')
        ->column('Icon', 'varchar(255)', true)
        ->column('Banner', 'varchar(255)', true)
        ->column('OwnerID', 'int', false, 'key')
        ->column('ChallengeID', 'varchar(36)', true )
        ->column('ChallengeUrl', 'varchar(255)', true)
        ->set(true, false);
}


// User Group Table
$Construct->table('UserGroup');
$UserGroupExists = $Construct->tableExists();
if(!$UserGroupExists) {
    $Construct
        ->column('UserID', 'int', false, 'primary')
        ->column('GroupID', 'int', false, ['primary', 'index'])
        ->column('Role', [GroupModel::ROLE_LEADER, GroupModel::ROLE_MEMBER], true)
        ->column('DateInserted', 'datetime', false)
        ->set($Explicit, $Drop);
}

//
// Upgrade Vanilla tables
//


$Construct->table('Discussion');
$DiscussionExists = $Construct->tableExists();
$GroupIDExists = $Construct->columnExists('GroupID');

if(!$GroupIDExists) {
    $Construct->column('GroupID', 'int', true, 'key');
    $Construct->set($Explicit, $Drop);
}
//
// Initial Data
//

// The Groups category with custom permissions


$PermissionModel = Gdn::permissionModel();
$RoleModel = new RoleModel();
$RoleModel->Database = $Database;
$RoleModel->SQL = $SQL;

// Flush permissions cache & loaded updated schema.
$PermissionModel->clearPermissions();

// If this is our initial Vanilla setup or the Group plugin
$PermissionModel->define(['Groups.Group.Add',
    'Groups.Moderation.Manage',
    'Groups.EmailInvitations.Add'] );
// Flush permissions cache & loaded updated schema.
$PermissionModel->clearPermissions();

// Update global permissions for Administrator roles
$adminRoles = $RoleModel->getByType(RoleModel::TYPE_ADMINISTRATOR)->resultArray();
foreach ($adminRoles as $role) {
    // It returns all permission: global/default category/custom category
    $permissions = $PermissionModel->getPermissions($role['RoleID']);
    foreach ($permissions as $permission) {
        Logger::event(
            'groups_plugin',
            Logger::INFO,
            'permissions',
            ['role' => $role['RoleID'] , 'value' => $permission]
        );
        if((array_key_exists('JunctionID', $permission))){
            continue;
        }
        if(array_key_exists('PermissionID', $permission)) {
            $permission['Groups.Group.Add'] = 1;
            $permission['Groups.Moderation.Manage'] = 0;
            $permission['Groups.EmailInvitations.Add'] = 1;
            $PermissionModel->save($permission);
        }

    }
}

// Update global permissions for Moderator roles
$moderatorRoles = $RoleModel->getByType(RoleModel::TYPE_MODERATOR)->resultArray();
foreach ($moderatorRoles as $role) {
    // It returns all permission: global/default category/custom category
    $permissions = $PermissionModel->getPermissions($role['RoleID']);
    foreach ($permissions as $permission) {
        if((array_key_exists('JunctionID', $permission))){
            continue;
        }
        if(array_key_exists('PermissionID', $permission)) {
            $permission['Groups.Group.Add'] = 0;
            $permission['Groups.Moderation.Manage'] = 1;
            $permission['Groups.EmailInvitations.Add'] = 0;
            $PermissionModel->save($permission);
        }
    }
}


$PermissionModel->Database = Gdn::database();
$PermissionModel->SQL = $SQL;

// Update default category permissions for Administrator/Moderator/Member roles
$roleTypes = [RoleModel::TYPE_ADMINISTRATOR, RoleModel::TYPE_MODERATOR, RoleModel::TYPE_MEMBER];

foreach ($roleTypes as $roleType) {
    $roles = $RoleModel->getByType($roleType)->resultArray();
    foreach ($roles as $role) {
        $permissions = $PermissionModel->getPermissions($role['RoleID']);
        foreach ($permissions as $permission) {
            Logger::event(
                'groups_plugin',
                Logger::INFO,
                'permissions:updated',
                ['role' => $role['RoleID'] , 'value' => $permission]
            );
            if(array_key_exists('PermissionID', $permission)) {
                $permission['Vanilla.Discussions.View'] = 1;
                $permission['Vanilla.Discussions.Add'] =  1;
                $permission['Vanilla.Discussions.Edit'] = 1;
                $permission['Vanilla.Discussions.Announce'] = 1; // Must be 1. Member role might be a leader of the Group. This permission is required to create announcements.
                $permission['Vanilla.Discussions.Sink'] =  $role['Type'] == RoleModel::TYPE_MODERATOR ||  $role['Type'] == RoleModel::TYPE_ADMINISTRATOR ? 1 : 0;
                $permission['Vanilla.Discussions.Close'] = $role['Type'] == RoleModel::TYPE_MODERATOR ||  $role['Type'] == RoleModel::TYPE_ADMINISTRATOR ? 1 : 0;
                $permission['Vanilla.Discussions.Delete'] = $role['Type'] == RoleModel::TYPE_MODERATOR ||  $role['Type'] == RoleModel::TYPE_ADMINISTRATOR ? 1 : 0;
                $permission['Vanilla.Comments.Add'] = 1;
                $permission['Vanilla.Comments.Edit'] = 0;
                $permission['Vanilla.Comments.Delete'] = 0;
                $PermissionModel->save($permission);
            }
        }
    }
}

// Force the user permissions to refresh.
$PermissionModel->clearPermissions();


// Insert some activity types
///  %1 = ActivityName
///  %2 = ActivityName Possessive: Username
///  %3 = RegardingName
///  %4 = RegardingName Possessive: Username, his, her, your
///  %5 = Link to RegardingName's Wall
///  %6 = his/her
///  %7 = he/she
///  %8 = RouteCode & Route
// X added a group
if ($SQL->getWhere('ActivityType', ['Name' => 'NewGroup'])->numRows() == 0) {
    $SQL->insert('ActivityType', ['AllowComments' => '0', 'Name' => 'NewGroup', 'FullHeadline' => '%1$s created a %8$s.', 'ProfileHeadline' => '%1$s created %8$s.', 'RouteCode' => 'group', 'Public' => '0']);
}

// X joined on a group
if ($SQL->getWhere('ActivityType', ['Name' => 'JoinedGroup'])->numRows() == 0) {
    $SQL->insert('ActivityType', ['AllowComments' => '0', 'Name' => 'JoinedGroup', 'FullHeadline' => '%1$s joined on %8$s.', 'ProfileHeadline' => '%1$s joined %8$s.', 'RouteCode' => 'group', 'Public' => '0']);
}

// X left on a group
if ($SQL->getWhere('ActivityType', ['Name' => 'LeftGroup'])->numRows() == 0) {
    $SQL->insert('ActivityType', ['AllowComments' => '0', 'Name' => 'LeftGroup', 'FullHeadline' => '%1$s left a group.', 'ProfileHeadline' => '%1$s left a group.', 'RouteCode' => 'group', 'Public' => '0']);
}

// changed group role for X
if ($SQL->getWhere('ActivityType', ['Name' => 'GroupRoleChange'])->numRows() == 0) {
    $SQL->insert('ActivityType', ['AllowComments' => '0', 'Name' => 'GroupRoleChange', 'FullHeadline' => '%%1$s changed %4$s group role.', 'ProfileHeadline' => '%1$s changed %4$s group role.', 'Notify' => '1']);
}

// X invite Y to Z
if ($SQL->getWhere('ActivityType', ['Name' => 'InviteToGroup'])->numRows() == 0) {
    $SQL->insert('ActivityType', ['AllowComments' => '0', 'Name' => 'InviteToGroup', 'FullHeadline' => '%%1$s invited %4$s to %8s.', 'ProfileHeadline' => '%1$s invited %4$s to %8s.', 'RouteCode' => 'group', 'Public' => '0','Notify' => '1']);
}

