<?php if (!defined('APPLICATION')) {
    exit();
}
use GroupModel;
/**
 * Groups database structure.
 */

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

$CategoryExists = $Construct->tableExists();
if ($SQL->getWhere('Category', ['Name' => 'Groups'])->numRows() == 0) {
    $SQL->insert('Category', ['ParentCategoryID' => -1, 'TreeLeft' => 2, 'TreeRight' => 3, 'Depth' => 1, 'InsertUserID' => 1,
        'UpdateUserID' => 1, 'DateInserted' => Gdn_Format::toDateTime(), 'DateUpdated' => Gdn_Format::toDateTime(),
        'Name' => 'Groups', 'UrlCode' => 'groups', 'Description' => 'Group discussions', 'PermissionCategoryID' => -1]);

}

if ($CategoryExists) {
    CategoryModel::instance()->rebuildTree();
    CategoryModel::instance()->recalculateTree();
    unset($CategoryModel);
}