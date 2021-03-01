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
        ->column('Type', [GroupModel::PRIVACY_PUBLIC, GroupModel::PRIVACY_PRIVATE, GroupModel::PRIVACY_SECRET], true)
        ->column('Deletable', 'tinyint(1)', '1')
        ->column('Closed', 'tinyint(1)', '0')
        ->column('DateInserted', 'datetime', false, 'index')
        ->column('Icon', 'varchar(255)', true)
        ->column('Banner', 'varchar(255)', true)
        ->column('OwnerID', 'int', false, 'key')
        ->column('ChallengeID', 'varchar(36)', true )
        ->column('ChallengeUrl', 'varchar(255)', true)
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

//
// Upgrade Vanilla tables
//

// Add 'GroupID' in Category
$Construct->table('Category');
$CategoryExists = $Construct->tableExists();
$GroupIDExists = $Construct->columnExists('GroupID');

if($CategoryExists && !$GroupIDExists) {
    $Construct->column('GroupID', 'int', true, 'key');
    $Construct->set($Explicit, $Drop);
}

$Construct->table('Discussion');
$DiscussionExists = $Construct->tableExists();
$GroupIDExists = $Construct->columnExists('GroupID');

if($DiscussionExists && !$GroupIDExists) {
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

// If this is our initial Vanilla setup or the Group plugin
$PermissionModel->define([
    'Groups.Group.Add',
    'Groups.Group.Delete',
    'Groups.Group.Edit',
    'Groups.Category.Manage',
    'Groups.Moderation.Manage',
    'Groups.EmailInvitations.Add'] );


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

//
// Update Vanilla tables and data
//

//Add extra columns in Category : https://github.com/topcoder-platform/forums/issues/178
if(!Gdn::structure()->table('Category')->columnExists('GroupID')) {
    Gdn::structure()->table('Category')
        ->column('GroupID', 'int', true, 'key')
        ->set(false, false);

    // Update data after adding GroupID column: https://github.com/topcoder-platform/forums/issues/178
    Gdn::sql()->query("UPDATE GDN_Category c
        INNER JOIN (SELECT c.CategoryID, g.GroupID FROM GDN_Category c , GDN_Group g WHERE c.UrlCode LIKE concat(g.ChallengeID,'%')) AS src
        ON src.CategoryID = c.CategoryID
        SET c.GroupID = src.GroupID
        WHERE c.GroupID IS NULL");
}



// Add the column Type in Group : https://github.com/topcoder-platform/forums/issues/133
if(! Gdn::structure()->table('Group')->columnExists('Privacy')) {
    if(Gdn::structure()->table('Group')->renameColumn('Type', 'Privacy')) {

        // Reset the internal state of this object so that it can be reused.
        Gdn::structure()->reset();

        Gdn::structure()->table('Group')
            ->column('Type', ['challenge', 'regular'], true)
            ->set(false, false);

        // Update existing data, all groups with ChallengeID will have the type 'challenge'
        Gdn::sql()->query("UPDATE GDN_Group g
                SET g.Type = CASE WHEN g.ChallengeID IS NOT NULL THEN 'challenge'
                ELSE 'regular' END");

        Gdn::structure()->table('Group')
            ->column('Type', ['challenge', 'regular'], false)
            ->set(false, false);
    }
}

// Add the column Archived in Group : https://github.com/topcoder-platform/forums/issues/136
if(!Gdn::structure()->table('Group')->columnExists('Archived')) {
    Gdn::structure()->table('Group')
        ->column('Archived', 'tinyint(1)', '0');
}

// FIX: https://github.com/topcoder-platform/forums/issues/449
if(!Gdn::structure()->tableExists('GroupInvitation')) {
    // Group  Invitation Table
    Gdn::structure()->table('GroupInvitation')
        ->primaryKey('GroupInvitationID')
        ->column('GroupID', 'int', false, 'index')
        ->column('Token', 'varchar(32)', false, 'unique')
        ->column('InvitedByUserID', 'int', false, 'index')
        ->column('InviteeUserID', 'int', false, 'index')
        ->column('DateInserted', 'datetime', false, 'index')
        ->column('Status', ['pending', 'accepted', 'declined', 'deleted'])
        ->column('DateAccepted', 'datetime', true)
        ->column('DateExpires', 'datetime')
        ->set(false, false);
}