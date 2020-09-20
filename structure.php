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
        ->column('ChallengeID', 'int', true, 'key')
        ->column('ChallengeLink', 'varchar(255)', true)
        ->set($Explicit, $Drop);
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

$Construct->table('Discussion');
$DiscussionExists = $Construct->tableExists();
$GroupIDExists = $Construct->columnExists('GroupID');

if(!$GroupIDExists) {
    $Construct->column('GroupID', 'int', true, 'key');
    $Construct->set($Explicit, $Drop);
}

$GroupsCategoryID = null;
$CategoryExists = $Construct->tableExists();
if ($SQL->getWhere('Category', ['Name' => 'Groups'])->numRows() == 0) {
    $GroupsCategoryID =$SQL->insert('Category', ['ParentCategoryID' => -1, 'TreeLeft' => 2, 'TreeRight' => 3, 'Depth' => 1, 'InsertUserID' => 1,
        'UpdateUserID' => 1, 'DateInserted' => Gdn_Format::toDateTime(), 'DateUpdated' => Gdn_Format::toDateTime(),
        'Name' => 'Groups', 'UrlCode' => 'groups', 'Description' => 'Group discussions', 'PermissionCategoryID' => -1, 'CanDelete' => 0]);

    // The Groups category with custom permissions
    if($GroupsCategoryID) {
        $SQL->put('Category', ['PermissionCategoryID' => $GroupsCategoryID], ['CategoryID' => $GroupsCategoryID]);
    }
} else {
    // The Groups category with custom permissions
    $GroupsCategoryID = $SQL->getWhere('Category', ['Name' => 'Groups'])->firstRow()->CategoryID;
    $SQL->update('Category')
        ->set('PermissionCategoryID', $GroupsCategoryID)
        ->where('CategoryID', $GroupsCategoryID)
        ->put();

}

$PermissionModel = Gdn::permissionModel();
$RoleModel = new RoleModel();
$RoleModel->Database = $Database;
$RoleModel->SQL = $SQL;

$adminRoles = $RoleModel->getByType(RoleModel::TYPE_ADMINISTRATOR)->resultArray();
foreach ($adminRoles as $role) {
    $permissions = $PermissionModel->getPermissions($role['RoleID']);
    foreach ($permissions as $permission) {
        if((array_key_exists('JunctionID', $permission) && $permission['JunctionID'] == -1) ){
            continue;
        }
        if((!array_key_exists('JunctionID', $permission) && array_key_exists('PermissionID', $permission)) ) {
            $permission['Groups.Group.Add'] = 1;
            $permission['Groups.Moderation.Manage'] = 0;
            $permission['Groups.EmailInvitations.Add'] = 1;
            $PermissionModel->save($permission);
        }
    }
}

$moderatorRoles = $RoleModel->getByType(RoleModel::TYPE_MODERATOR)->resultArray();
foreach ($moderatorRoles as $role) {
    $permissions = $PermissionModel->getPermissions($role['RoleID']);
    foreach ($permissions as $permission) {
        if((array_key_exists('JunctionID', $permission) && $permission['JunctionID'] == -1) ){
            continue;
        }
        if(!array_key_exists('JunctionID', $permission) && array_key_exists('PermissionID', $permission)) {
            $permission['Groups.Group.Add'] = 0;
            $permission['Groups.Moderation.Manage'] = 1;
            $permission['Groups.EmailInvitations.Add'] = 0;
            $PermissionModel->save($permission);
        }

    }
}


$PermissionModel->Database = Gdn::database();
$PermissionModel->SQL = $SQL;

$roleTypes = [RoleModel::TYPE_ADMINISTRATOR, RoleModel::TYPE_MODERATOR, RoleModel::TYPE_MEMBER];
// Create custom permissions for the Groups category
if ($SQL->getWhere('Permission', ['JunctionTable' => 'Category', 'JunctionID' => $GroupsCategoryID ])->numRows() == 0) {
    foreach ($roleTypes as $roleType) {
        $roles = $RoleModel->getByType($roleType)->resultArray();
        foreach ($roles as $role) {
            $GroupsCategoryPermission = [
                'RoleID' => $role['RoleID'],
                'JunctionTable' => 'Category',
                'JunctionColumn' => 'PermissionCategoryID',
                'JunctionID' => $GroupsCategoryID,
                'Vanilla.Discussions.View' => 1,
                'Vanilla.Discussions.Add' => 1,
                'Vanilla.Discussions.Edit' => 1,
                'Vanilla.Discussions.Announce' => 1, // Must be 1. Member might be a leader of the Group. Need this permission to create announcements.
                'Vanilla.Discussions.Sink' =>  $role['Type'] == RoleModel::TYPE_MODERATOR ||  $role['Type'] == RoleModel::TYPE_ADMINISTRATOR ? 1 : 0,
                'Vanilla.Discussions.Close' => $role['Type'] == RoleModel::TYPE_MODERATOR ||  $role['Type'] == RoleModel::TYPE_ADMINISTRATOR ? 1 : 0,
                'Vanilla.Discussions.Delete' => 1,
                'Vanilla.Comments.Add' => 1,
                'Vanilla.Comments.Edit' => 0,
                'Vanilla.Comments.Delete' => 0
            ];
            $PermissionModel->save($GroupsCategoryPermission, true);
        }
    }
}

if ($CategoryExists) {
    CategoryModel::instance()->rebuildTree();
    CategoryModel::instance()->recalculateTree();
    unset($CategoryModel);
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

