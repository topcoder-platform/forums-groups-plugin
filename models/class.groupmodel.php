<?php
/**
 * Class GroupModel
 */
class GroupModel extends Gdn_Model {
    /** Slug for PUBLIC type. */
    const TYPE_PUBLIC = 'public';

    /** Slug for PRIVATE type. */
    const TYPE_PRIVATE = 'private';

    /** Slug for SECRET type. */
    const TYPE_SECRET = 'secret';

    const ROLE_MEMBER = 'member';

    const ROLE_LEADER = 'leader';

    /**
     * Class constructor. Defines the related database table name.
     */
    public function __construct() {
        parent::__construct('Group');
        $this->fireEvent('Init');
    }

    /**
     * Clear the groups cache.
     */
    public function clearCache() {
        $key = 'Groups';
        Gdn::cache()->remove($key);
    }

    /**
     * Define a group.
     *
     * @param $values
     */
    public function define($values) {
        if (array_key_exists('GroupID', $values)) {
            $groupID = $values['GroupID'];
            unset($values['GroupID']);

            $this->SQL->replace('Group', $values, ['GroupID' => $groupID], true);
        } else {
            // Check to see if there is a group with the same name.
            $groupID = $this->SQL->getWhere('Group', ['Name' => $values['Name']])->value('GroupID', null);

            if (is_null($groupID)) {
                // Figure out the next group ID.
                $maxGroupID = $this->SQL->select('r.GroupID', 'MAX')->from('Group r')->get()->value('GroupID', 0);
                $groupID = $maxGroupID + 1;
                $values['GroupID'] = $groupID;

                // Insert the group.
                $this->SQL->insert('Group', $values);
            } else {
                // Update the group.
                $this->SQL->update('Group', $values, ['GroupID' => $groupID])->put();
            }
        }
        $this->clearCache();
    }

    /**
     * Get max pages
     * @return int
     */
    public function getMaxPages() {
        return (int)c('Vanilla.Groups.MaxPages')? : 100;
    }

    /**
     * @inheritdoc
     */
    public function getDefaultLimit() {
        return c('Vanilla.Groups.PerPage', 30);
    }


    /**
     * Default Gdn_Model::get() behavior.
     *
     * Prior to 2.0.18 it incorrectly behaved like GetID.
     * This method can be deleted entirely once it's been deprecated long enough.
     *
     * @return object DataSet
     */
    public function get($orderFields = '', $orderDirection = 'asc', $limit = false, $offset = false) {
        return parent::get($orderFields, $orderDirection, $limit, $offset);
    }

    /**
     * Get the list of public group IDs
     *
     */
    public function getAllPublicGroupIDs(){
        $sql = $this->SQL;

        // Build up the base query. Self-join for optimization.
        $sql->select('g.GroupID')
            ->from('Group g')
            ->where('g.Type' , [GroupModel::TYPE_PUBLIC] );

        $data = $sql->get()->resultArray();
        return array_column($data, 'GroupID');
    }


    /**
     * Get the list of groups as categories for the current user
     *
     */
    public function getAllGroupsAsCategoryIDs(){
        $sql = $this->SQL;
        $sql->select('c.*')
            ->from('Category c')
            ->leftJoin('Group g', 'c.UrlCode = g.ChallengeID')
            ->leftJoin('UserGroup ug', 'ug.GroupID = g.GroupID')
            ->where('ug.UserID' , Gdn::session()->UserID );

        $data = $sql->get()->resultArray();
        return array_column($data, 'CategoryID');
    }

    /**
     * Get all availbale categories for users based on group membership
     * @return array
     */
    public function getAllAvailableCategories() {
        $userGroupCategoryIDs =  $this->getAllGroupsAsCategoryIDs();
        $ancestors = $this->getAncestors($userGroupCategoryIDs);
        $ancestorIDs = array_column($ancestors, 'CategoryID');
        return array_unique(array_values(array_merge($ancestorIDs, $userGroupCategoryIDs)));
    }

    /**
     * Challenge Forums
     * @return object
     */
    public function getChallengesForums() {
        $categoryModel  = new CategoryModel();
         return $categoryModel->getByCode('challenges-forums');
    }

    public function checkGroupCategoryPermissions($categoryTree) {
        if(Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return $categoryTree;
        }

        $categoriesWithGroupPermissions = $this->getAllAvailableCategories();
        $userGroupCategoryIDs =  $this->getAllGroupsAsCategoryIDs();
        $categoryModel = new CategoryModel();
        $challengesForumsCategory = $this->getChallengesForums();
        $challengesForumsCategoryID = val('CategoryID',$challengesForumsCategory);
        foreach ($categoryTree as &$category) {
            $categoryID = $category['CategoryID'];

            $loadedCategory =  $categoryModel->getID($categoryID, DATASET_TYPE_ARRAY);
            // CategoriesController has an invalid category'depth
            $depth = $loadedCategory['Depth'];
            $parentCategoryID =  $category['ParentCategoryID'];

            if($depth < 2) {
                if($challengesForumsCategoryID == $categoryID) {
                    $category['isDisplayed'] = count($categoriesWithGroupPermissions) > 0;
                } else {
                    $category['isDisplayed'] = true;
                }
            } else {
                $category['isDisplayed'] = false;
                if ($depth  == 2 && $parentCategoryID != $challengesForumsCategoryID) {
                    $category['isDisplayed'] = true;
                } else if ($depth  >= 2 && (in_array($categoryID, $categoriesWithGroupPermissions)
                        || in_array($parentCategoryID, $userGroupCategoryIDs)
                    )) {
                    $category['isDisplayed'] = true;
                }
            }
        }

        return array_filter($categoryTree, function($e) {
            return ($e['isDisplayed'] == true);
        });
    }


    /**
     * List of Categories
     * @param $categoryIDs
     * @return bool|Gdn_DataSet
     */
    public function getAncestors($categoryIDs) {
        return $this->SQL
            ->select('c.ParentCategoryID, c.CategoryID, c.TreeLeft, c.TreeRight, c.Depth, c.Name, c.Description, c.CountDiscussions, c.CountComments, c.AllowDiscussions, c.UrlCode')
            ->from('Category c')
            ->join('Category d', 'c.TreeLeft < d.TreeLeft and c.TreeRight > d.TreeRight')
            ->where('d.CategoryID', $categoryIDs)
            ->orderBy('c.TreeLeft', 'asc')
            ->get()
            ->resultArray();
    }

    /**
     * Get all available groups including private ines
     */
    public function getAvailableGroups($where =[], $orderFields = '', $limit = false, $offset = false) {

        if ($limit === 0) {
            trigger_error("You should not supply 0 to for $limit in GroupModel->getAvailableGroups()", E_USER_NOTICE);
        }
        if (empty($limit)) {
            $limit = c('Vanilla.Groups.PerPage', 30);
        }
        if (empty($offset)) {
            $offset = 0;
        }

        if (!is_array($where)) {
            $where = [];
        }

        $sql = $this->SQL;

        $groupTypes = [GroupModel::TYPE_PUBLIC, GroupModel::TYPE_PRIVATE];
        if(Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            array_push($groupTypes,GroupModel::TYPE_SECRET);
        }

        // Build up the base query. Self-join for optimization.
        $sql->select('g.*')
            ->from('Group g')
            ->leftjoin('UserGroup ug', 'ug.GroupID=g.GroupID and ug.UserID='.Gdn::session()->UserID)
            ->where('ug.UserID' , null)
            ->where('g.Type' , $groupTypes )
            ->where($where)
            ->limit($limit, $offset);

        foreach ($orderFields as $field => $direction) {
            $sql->orderBy($this->addFieldPrefix($field), $direction);
        }

        $data = $sql->get();
        return $data;
    }

    /**
     * Get count of available groups
     */
    public function countAvailableGroups($where =[]) {

        if (!is_array($where)) {
            $where = [];
        }

        $sql = $this->SQL;

        $groupTypes = [GroupModel::TYPE_PUBLIC, GroupModel::TYPE_PRIVATE];
        if(Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            array_push($groupTypes,GroupModel::TYPE_SECRET);
        }

        // Build up the base query. Self-join for optimization.
        $sql->select('g.*')
            ->from('Group g')
            ->leftjoin('UserGroup ug', 'ug.GroupID=g.GroupID and ug.UserID='.Gdn::session()->UserID)
            ->where('ug.UserID' , null)
            ->where('g.Type' , $groupTypes)
            ->where($where);

        $data = $sql->get()
            ->firstRow();

        return $data === false ? 0 : $data->Count;
    }

   /**
    * Get the list of groups for the current user
    *
    */
    public function getMyGroups($where =[], $orderFields = '', $limit = false, $offset = false) {
        if ($limit === 0) {
            trigger_error("You should not supply 0 to for $limit in GroupModel->getLeaders()", E_USER_NOTICE);
        }
        if (empty($limit)) {
            $limit = c('Vanilla.Groups.PerPage', 30);
        }
        if (empty($offset)) {
            $offset = 0;
        }

        if (!is_array($where)) {
            $where = [];
        }

        $sql = $this->SQL;

        // Build up the base query. Self-join for optimization.
        $sql->select('g.*, ug.Role, ug.DateInserted')
            ->from('Group g')
            ->join('UserGroup ug', 'ug.GroupID=g.GroupID and ug.UserID='.Gdn::session()->UserID)
            ->limit($limit, $offset);

        foreach ($orderFields as $field => $direction) {
            $sql->orderBy($this->addFieldPrefix($field), $direction);
        }

        $sql->where($where);

        $data = $sql->get();
        return $data;
    }


    /**
     * Get count of the groups fir the current user
     */
    public function countMyGroups($where =[]) {
        if (!is_array($where)) {
            $where = [];
        }

        $sql = $this->SQL;

        // Build up the base query. Self-join for optimization.
        $sql->select('count(*) Count')
            ->from('Group g')
            ->join('UserGroup ug', 'ug.GroupID=g.GroupID and ug.UserID='.Gdn::session()->UserID)
            ->where($where);

        $data = $sql->get()
            ->firstRow();

        return $data === false ? 0 : $data->Count;
    }

    /**
     * Join a new member
     * @param $GroupID
     * @param $UserID
     * @return bool|Gdn_DataSet|object|string
     */
    public function join($GroupID, $UserID){
        $Fields = ['Role' => GroupModel::ROLE_MEMBER, 'GroupID' => $GroupID,'UserID' => $UserID, 'DateInserted' => Gdn_Format::toDateTime()];
        if( $this->SQL->getWhere('UserGroup', ['GroupID' => $GroupID,'UserID' => $UserID])->numRows() == 0) {
            $this->SQL->insert('UserGroup', $Fields);
            $this->notifyJoinGroup($GroupID, $UserID);
        }
    }

    /**
     * Invite a new member
     * @param $GroupID
     * @param $UserID
     * @return bool|Gdn_DataSet|object|string
     */
    public function invite($GroupID, $UserID){
        $this->sendInviteEmail($GroupID, $UserID);
    }

    /**
     * Accept an invitation
     * @param $GroupID
     * @param $UserID
     * @return bool|Gdn_DataSet|object|string
     */
    public function accept($groupID, $userID){
        $this->join($groupID, $userID);
    }

    /**
     * Returntur if user is a member of the group
     *
     */
    public function isMemberOfGroup($userID, $groupID) {
        $groups = $this->memberOf($userID);
        foreach ($groups as $group) {
            if ($group->GroupID == $groupID) {
                return true;
            }
        }
        return false;
    }

    /**
     * Set a new role for a member
     * @param $GroupID
     * @param $MemberID
     * @param $Role
     * @return bool|Gdn_DataSet|object|string
     * @throws Exception
     */
    public function setRole($GroupID, $MemberID, $Role){
        return $this->SQL->update('UserGroup')
            ->set('Role' , $Role)
            ->where('GroupID' , $GroupID)
            ->where('UserID', $MemberID)
            ->put();
    }

    /**
     * Remove a member from group
     *
     * @param $GroupID
     * @param $MemberID
     * @return bool|Gdn_DataSet|object|string|void
     */
    public function removeMember($GroupID, $MemberID){
        return $this->SQL->delete('UserGroup', ['GroupID' => $GroupID, 'UserID' => $MemberID]);
    }

    /**
     * Get leaders
     *
     * @return object DataSet
     */
    public function getLeaders($groupID, $where =[], $orderFields = '', $limit = false, $offset = false) {
        $where = array_merge(['Role' => GroupModel::ROLE_LEADER], $where);
        return $this->getUserGroups($groupID, $where, $orderFields, $limit , $offset);
    }

    /**
     * Get members
     *
     * @param $groupID
     * @param array $where
     * @param string $orderFields
     * @param bool $limit
     * @param bool $offset
     * @return object DataSet
     */
    public function getMembers($groupID, $where =[], $orderFields = '', $limit = false, $offset = false) {
        return $this->getUserGroups($groupID, $where, $orderFields , $limit, $offset);
    }

    private function getUserGroups($groupID, $where =[], $orderFields = '', $limit = false, $offset = false) {
        if ($limit === 0) {
            trigger_error("You should not supply 0 to for $limit in GroupModel->getLeaders()", E_USER_NOTICE);
        }
        if (empty($limit)) {
            $limit = c('Vanilla.Groups.PerPage', 30);
        }
        if (empty($offset)) {
            $offset = 0;
        }

        if (!is_array($where)) {
            $where = [];
        }

        $sql = $this->SQL;

        // Build up the base query. Self-join for optimization.
        $sql->select('u.*, ug.Role, ug.DateInserted')
            ->from('UserGroup ug')
            ->join('User u', 'ug.UserID = u.UserID and ug.GroupID='.$groupID)
            ->limit($limit, $offset);

        foreach ($orderFields as $field => $direction) {
            $sql->orderBy($this->addFieldPrefix($field), $direction);
        }

        $sql->where($where);

        $data = $sql->get()->resultArray();;
        return $data;
    }

    /**
     * Returns a resultset of group data related to the specified GroupID.
     *
     * @param int The GroupID to filter to.
     */
    public function getByGroupID($groupID) {
        return $this->getWhere(['GroupID' => $groupID])->firstRow();
    }

    /**
     * Save group data.
     *
     * @param array $formPostValues The group row to save.
     * @param array|false $settings Additional settings for the save.
     * @return bool|mixed Returns the group ID or false on error.
     */
    public function save($formPostValues, $settings = false) {
        // Define the primary key in this model's table.
        $this->defineSchema();

        $groupID = val('GroupID', $formPostValues);
        $ownerID = val('OwnerID', $formPostValues);
        $insert = $groupID > 0 ? false : true;

        if ($insert) {
            // Figure out the next group ID.
            $maxGroupID = $this->SQL->select('g.GroupID', 'MAX')->from('Group g')->get()->value('GroupID', 0);
            $groupID = $maxGroupID + 1;

            $this->addInsertFields($formPostValues);
            $formPostValues['GroupID'] = strval($groupID); // string for validation
        } else {
            $this->addUpdateFields($formPostValues);
        }

        // Validate the form posted values
        if ($this->validate($formPostValues, $insert)) {
            $fields = $this->Validation->schemaValidationFields();
            $fields = $this->coerceData($fields);

            if ($insert === false) {
                $this->update($fields, ['GroupID' => $groupID]);
            } else {
                $this->insert($fields);
                $this->SQL->insert(
                    'UserGroup',
                    [
                        'UserID' => $ownerID,
                        'GroupID' => $groupID,
                        'Role' => GroupModel::ROLE_LEADER,
                        'DateInserted' => Gdn_Format::toDateTime()
                    ]
                );
                $this->notifyNewGroup($groupID, $fields['Name']);
            }

           if (Gdn::cache()->activeEnabled()) {
                // Don't update the user table if we are just using cached permissions.
                $this->clearCache();
            }
        } else {
            $groupID = false;
        }
        return $groupID;
    }

    /**
     * Delete a group.
     *
     * @param int $groupID The ID of the group to delete.
     * @param array $options An array of options to affect the behavior of the delete.
     *
     * @return bool Returns **true** on success or **false** otherwise.
     */
    public function deleteID($groupID, $options = []) {
        $this->SQL->delete('UserGroup', ['GroupID' => $groupID]);
        return $this->SQL->delete('Group', ['GroupID' => $groupID]);
    }

    /**
     * Validate a group
     * @inheritdoc
     */
    public function validate($values, $insert = false) {

        return parent::validate($values, $insert);
    }

    /**
     * Get count of members
     * @param $groupId
     * @param null $role
     * @return mixed
     */
    public function countOfMembers($groupId, $role = null){
        $sql = $this->SQL;
        $where = ['GroupID' => $groupId];
        if($role) {
            $where['Role']= $role;
        }

        return $sql->getCount('UserGroup', $where);
    }

    /**
     * Get all groups for the specified user
     * @param $userID
     * @return array|mixed|null
     */
    public function memberOf($userID){
        $sql = $this->SQL;
        $result = $sql->select('ug.Role, ug.GroupID')
            ->from('UserGroup ug')
            ->where('UserID', $userID)
            ->get();
        return $result->result();
    }

    /**
     * Get a group role
     */
    public function getGroupRoleFor($userID, $groupID) {
        $sql = $this->SQL;
        $result = $sql->select('ug.Role')
            ->from('UserGroup ug')
            ->where('UserID', $userID)
            ->where('GroupID', $groupID)
            ->get()->firstRow();
        return $result;
    }

    /**
     * Check group view permission
     *
     */
    public function canView($group) {
        if($group->Type == GroupModel::TYPE_PUBLIC){
            return true;
        } else {
            $result = $this->getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
            $groupRole = val('Role', $result, null);
            if($groupRole ||  Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check add group permission
     *
     */
    public function canAddGroup() {
        return Gdn::session()->checkPermission(GroupsPlugin::GROUPS_GROUP_ADD_PERMISSION);
    }

    /**
     * Check edit group permission
     *
     */
    public function canEdit($group) {
       $result = $this->getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
       $groupRole = val('Role', $result, null);
       if($groupRole == GroupModel::ROLE_LEADER ||
           Gdn::session()->UserID == $group->OwnerID ||
           Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
           return true;
       }

       return false;
    }

    /**
     * Check delete group permission
     *
     */
    public function canDelete($group){
        return Gdn::session()->UserID == $group->OwnerID;
    }

    /**
     *  Check join group permission
     *
     */
    public function canJoin($group) {
        return $group->Type == GroupModel::TYPE_PUBLIC;
    }

    /**
     *  Check remove member permission
     *
     */
    public function canRemoveMember($group) {
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        $groupRole = val('Role', $result, null);
        if($groupRole == GroupModel::ROLE_LEADER ||
            Gdn::session()->UserID == $group->OwnerID) {
            return true;
        }
        return false;
    }

    /**
     *  Check change group role permission
     *
     */
    public function canChangeGroupRole($group) {
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        $groupRole = val('Role', $result, null);
        if($groupRole == GroupModel::ROLE_LEADER ||
            Gdn::session()->UserID == $group->OwnerID) {
            return true;
        }
        return false;
    }

    /**
     *  Check leave group permission
     *
     */
    public function canLeave($group) {
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        $groupRole = val('Role', $result, null);
        if(Gdn::session()->UserID == $group->OwnerID) {
            return false;
        }

        return $groupRole != null;
    }

    /**
     *  Check manage members permission
     *
     */
    public function canManageMembers($group) {
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        $groupRole = val('Role', $result, null);
        if($groupRole == GroupModel::ROLE_LEADER ||
            Gdn::session()->UserID == $group->OwnerID) {
            return true;
        }
        return false;
    }

    /**
     *  Check invite member permission
     *
     */
    public function canInviteNewMember($group) {
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        $groupRole = val('Role', $result, null);
        if($groupRole === GroupModel::ROLE_LEADER ||
            Gdn::session()->UserID === $group->OwnerID ||
            Gdn::session()->checkPermission(GroupsPlugin::GROUPS_EMAIL_INVITATIONS_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check add group discusion permission
     *
     */
    public function canAddDiscussion($group) {
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        $groupRole = val('Role', $result, null);
        if($groupRole || Gdn::session()->UserID == $group->OwnerID) {
            return true;
        }
        return false;
    }

    /**
     *  Check add  group announcement permission
     *
     */
    public function canAddAnnouncement($group) {
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $group->GroupID);
        $groupRole = val('Role', $result, null);
        if($groupRole === GroupModel::ROLE_LEADER || Gdn::session()->UserID == $group->OwnerID) {
            return true;
        }
        return false;
    }

    /**
     *  Check view group discusion permission
     *
     */
    public function canViewDiscussion($discussion) {
        $groupID= $discussion->GroupID;
        if(!$groupID) {
            return true;
        }
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $groupID);
        $groupRole = val('Role', $result, null);
        if($groupRole || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check edit group discusion permission
     *
     */
    public function canEditDiscussion($discussion) {
        $canEditDiscussion = DiscussionModel::canEdit($discussion) ;
        $groupID= $discussion->GroupID;
        if(!$groupID) {
            return $canEditDiscussion;
        }
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $groupID);
        $groupRole = val('Role', $result, null);

        if(($canEditDiscussion && $groupRole && $discussion->IInsertUserID == Gdn::session()->UserID)
            || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check dismiss group discusion permission
     *
     */
    public function canDismissDiscussion($discussion) {
        $canDismissDiscussion =  CategoryModel::checkPermission($discussion->CategoryID, 'Vanilla.Discussions.Dismiss', true)
        && $discussion->Announce
        && !$discussion->Dismissed
        && Gdn::session()->isValid();

        $groupID= $discussion->GroupID;
        if(!$groupID ) {
            return $canDismissDiscussion;
        }

        if($canDismissDiscussion === false) {
            return $canDismissDiscussion;
        }

        $group = $this->getByGroupID($groupID);
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $groupID);
        $groupRole = val('Role', $result, null);
        if($groupRole === GroupModel::ROLE_LEADER || Gdn::session()->UserID === $group->OwnerID
                || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check announce group discusion permission
     *
     */
    public function canAnnounceDiscussion($discussion) {
        $canAnnounceDiscussion =  CategoryModel::checkPermission($discussion->CategoryID, 'Vanilla.Discussions.Announce', true);
        $groupID = $discussion->GroupID;
        if(!$groupID ) {
            return $canAnnounceDiscussion;
        }

        if($canAnnounceDiscussion === false) {
            return $canAnnounceDiscussion;
        }

        $group = $this->getByGroupID($groupID);
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $groupID);
        $groupRole = val('Role', $result, null);
        if($groupRole === GroupModel::ROLE_LEADER || Gdn::session()->UserID === $group->OwnerID
            || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check announce group discusion permission
     *
     */
    public function canAddComment($categoryID, $groupID) {
        $canAddComment =  CategoryModel::checkPermission($categoryID, 'Vanilla.Comments.Add', true);
        if(!$groupID ) {
            return $canAddComment;
        }

        if($canAddComment === false) {
            return $canAddComment;
        }

        $group = $this->getByGroupID($groupID);
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $groupID);
        $groupRole = val('Role', $result, null);
        if($groupRole || Gdn::session()->UserID === $group->OwnerID
            || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check sink group discusion permission
     *
     */
    public function canSinkDiscussion($discussion) {
        $canSinkDiscussion =  CategoryModel::checkPermission($discussion->CategoryID, 'Vanilla.Discussions.Sink', true);
        $groupID = $discussion->GroupID;
        if(!$groupID ) {
            return $canSinkDiscussion;
        }

        if($canSinkDiscussion === false) {
            return $canSinkDiscussion;
        }

        $group = $this->getByGroupID($groupID);
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $groupID);
        $groupRole = val('Role', $result, null);
        if($groupRole === GroupModel::ROLE_LEADER || Gdn::session()->UserID === $group->OwnerID
            || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check close group discusion permission
     *
     */
    public function canCloseDiscussion($discussion) {
        $canCloseDiscussion =  CategoryModel::checkPermission($discussion->CategoryID, 'Vanilla.Discussions.Close', true);
        $groupID = $discussion->GroupID;
        if(!$groupID ) {
            return $canCloseDiscussion;
        }

        if($canCloseDiscussion === false) {
            return $canCloseDiscussion;
        }

        $group = $this->getByGroupID($groupID);
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $groupID);
        $groupRole = val('Role', $result, null);
        if($groupRole === GroupModel::ROLE_LEADER || Gdn::session()->UserID === $group->OwnerID
            || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    /**
     *  Check move group discusion permission
     *
     */
    public function canMoveDiscussion($discussion) {
        $groupID = $discussion->GroupID;
        if(!$groupID ) {
            return $this->canEditDiscussion($discussion);
        }

        $group = $this->getByGroupID($groupID);
        $result = $this->getGroupRoleFor(Gdn::session()->UserID, $groupID);
        $groupRole = val('Role', $result, null);
        if($groupRole === GroupModel::ROLE_LEADER || Gdn::session()->UserID === $group->OwnerID
            || Gdn::session()->checkPermission(GroupsPlugin::GROUPS_MODERATION_MANAGE_PERMISSION)) {
            return true;
        }
        return false;
    }

    public function canRefetchDiscussion($discussion) {
        $groupID = $discussion->GroupID;
        return $this->canEditDiscussion($discussion);
    }

    public function canDeleteDiscussion($discussion) {
        $canDeleteDiscussion =  CategoryModel::checkPermission($discussion->CategoryID, 'Vanilla.Discussions.Delete', true);
        $groupID= $discussion->GroupID;
        if(!$groupID) {
             return $canDeleteDiscussion;
        }

        $group = $this->getByGroupID($groupID);
        if($canDeleteDiscussion && Gdn::session()->UserID == $group->OwnerID) {
            return true;
        }
        return false;
    }

    /**
     * Send invite email.
     *
     * @param int $userID
     * @param string $password
     */
    public function sendInviteEmail($GroupID, $userID) {
        $Group = $this->getByGroupID($GroupID);
        $session = Gdn::session();
        $sender = Gdn::userModel()->getID($session->UserID);
        $user = Gdn::userModel()->getID($userID);
        $appTitle = Gdn::config('Garden.Title');
        $email = new Gdn_Email();
        $email->subject('['.$appTitle.'] '.$sender->Name.' invited you to '.$Group->Name);
        $email->to($user->Email);
        $greeting = 'Hello!';
        $message = $greeting.'<br/>'.
            'You can accept or decline this invitation.';

        $emailTemplate = $email->getEmailTemplate()
            ->setTitle('['.$appTitle.'] '.$sender->Name.' invited you to '.$Group->Name)
            ->setMessage($message)
            ->setButton(externalUrl('/group/accept/'.$Group->GroupID.'?userID='.$userID), 'Accept' );

        $email->setEmailTemplate($emailTemplate);

        try {
            $email->send();
        } catch (Exception $e) {
            if (debug()) {
                throw $e;
            }
        }
    }


    public function notifyNewGroup($groupID, $groupName) {
        $activityModel = Gdn::getContainer()->get(ActivityModel::class);
        $data = [
            "ActivityType" => 'NewGroup',
            "ActivityUserID" => Gdn::session()->UserID,
            "HeadlineFormat" => '{ActivityUserID,user} created <a href="{Url,html}">{Data.Name,text}</a>',
            "RecordType" => "Group",
            "RecordID" => $groupID,
            "Route" => groupUrl($groupID, "", "/"),
            "Data" => [
                "Name" => $groupName,
            ]
        ];
        $activityModel->save($data);
    }

    public function notifyJoinGroup($groupID, $userID) {
        $group = $this->getID($groupID);

        $activityModel = Gdn::getContainer()->get(ActivityModel::class);
        $data = [
            "ActivityType" => 'JoinedGroup',
            "ActivityUserID" => $userID,
            "HeadlineFormat" => '{ActivityUserID,user} joined <a href="{Url,html}">{Data.Name,text}</a>',
            "RecordType" => "Group",
            "RecordID" => $group->GroupID,
            "Route" => groupUrl($group->GroupID, "", "/"),
            "Data" => [
                "Name" => $group->Name,
            ]
        ];
        $activityModel->save($data);
    }
}