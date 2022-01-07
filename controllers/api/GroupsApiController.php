<?php

use Garden\Web\Exception\ClientException;
use Garden\Schema\Schema;
use Vanilla\Utility\InstanceValidatorSchema;
use Garden\Web\Data;
use Garden\Web\Exception\NotFoundException;
use Garden\Web\Exception\ServerException;
use Vanilla\ApiUtils;

/**
 * Groups API Controller for the `/groups` resource.
 */
class GroupsApiController extends AbstractApiController {

    /** @var UserMetaModel */
    private $userMetaModel;
    /** @var GroupModel */
    private $groupModel;

    /** @var CategoryModel */
    private $categoryModel;

    private $groupSchema;

    private $groupPostSchema;

    private $groupMemberPostSchema;

    private $groupMemberDetailsSchema;
    /**
     * GroupsApiController constructor.
     *
     * @param UserMetaModel $userMetaModel
     * @param GroupModel $groupModel
     */
    public function __construct(UserMetaModel $userMetaModel, GroupModel $groupModel, CategoryModel $categoryModel) {
        $this->userMetaModel = $userMetaModel;
        $this->groupModel = $groupModel;
        $this->categoryModel = $categoryModel;
    }

    /**
     * List of groups.
     *
     * @param array $query The query string.
     * @return Data
     */
    public function index(array $query) {
        $this->permission();

        $in = $this->schema([
            'challengeID:s?' => [
                'description' => 'Filter by Topcoder challenge ID.',
                'x-filter' => [
                    'field' => 'ChallengeID'
                ],
            ],
            'privacy:s?' => [
                'description' => 'Filter by group privacy.',
                'x-filter' => [
                    'field' => 'Privacy'
                ],
            ],
            'type:s?' => [
                'description' => 'Filter by group type.',
                'x-filter' => [
                    'field' => 'Type'
                ],
            ],
            'page:i?' => [
                'description' => 'Page number. See [Pagination](https://docs.vanillaforums.com/apiv2/#pagination).',
                'default' => 1,
                'minimum' => 1,
                'maximum' => $this->groupModel->getMaxPages()
            ],
            'limit:i?' => [
                'description' => 'Desired number of items per page.',
                'default' => $this->groupModel->getDefaultLimit(),
                'minimum' => 1,
                'maximum' => 100
            ],
        ], ['GroupIndex', 'in'])->setDescription('List groups.');
        $out = $this->schema([':a' => $this->groupSchema()], 'out');

        $query = $this->filterValues($query);
        $query = $in->validate($query);

        $where = ApiUtils::queryToFilters($in, $query);

        list($offset, $limit) = offsetLimit("p{$query['page']}", $query['limit']);

        $rows = $this->groupModel->getWhere($where, '', '', $limit, $offset)->resultArray();

        $result = $out->validate($rows);

        $paging = ApiUtils::morePagerInfo($rows, '/api/v2/groups', $query, $in);

        return new Data($result, ['paging' => $paging]);
    }

    /**
     * Lookup a group by its numeric ID.
     *
     * @param int $id The group ID
     * @throws NotFoundException if the group cannot be found.
     * @return array
     */
    public function get($id) {
        $this->permission();
        $group = $this->groupModel->getID($id, DATASET_TYPE_ARRAY);
        if (!$group) {
            throw new NotFoundException('Group');
        }
        return ApiUtils::convertOutputKeys($group);
    }

    /**
     * Add a group.
     *
     * @param array $body The request body.
     * @throws ServerException if the group could not be created.
     * @return array
     */
    public function post(array $body) {
        $this->permission(GroupsPlugin::GROUPS_GROUP_ADD_PERMISSION);
        $in = $this->groupPostSchema()->setDescription('Add a group.');
        $out = $this->groupSchema('out');
        $body = $in->validate($body);
        $groupData = ApiUtils::convertInputKeys($body);
        $groupData['OwnerID'] = $this->getSession()->UserID;
        $id = $this->groupModel->save($groupData);
        $this->validateModel($this->groupModel);
        if (!$id) {
            throw new ServerException('Unable to insert a group.', 500);
        }
        $row = $this->groupModel->getID($id, DATASET_TYPE_ARRAY);
        $result = $out->validate($row);
        return $result;
    }

    /**
     * Update a group.
     *
     * @param array $body The request body.
     * @throws ServerException if the group could not be updated.
     * @return array
     */
    public function patch($id, array $body) {
        $in = $this->groupPatchSchema()->setDescription('Update a group.');
        $out = $this->groupSchema('out');
        $body = $in->validate($body);
        $group = $this->groupModel->getByGroupID($id);
        if(!$group) {
            throw new NotFoundException('Group');
        }
        $group->Name = $body['name'];
        $result = $this->groupModel->save($group);
        $this->validateModel($this->groupModel);
        if ($result == false) {
            throw new ServerException('Unable to update a group.', 500);
        }
        $row = $this->groupModel->getID($id, DATASET_TYPE_ARRAY);
        return $out->validate($row);
    }

    /**
     * Add participants to a group.
     *
     * @param int $id The ID of the group.
     * @param array $body The request body.
     * @throws NotFoundException if the group or user could not be found.
     * @throws ServerException If the user could not be added.
     * @return array
     */
    public function post_members($id, array $body) {
        $this->idParamSchema();

        $in = $this->groupMemberPostSchema('in')->setDescription('Add a member to a group.');
        $out = $this->schema($this->idUserIdParamSchema(), 'out');

        $body = $in->validate($body);

        $group = $this->groupModel->getByGroupID($id);
        if(!$group) {
            throw new NotFoundException('Group');
        }

        $userID = $body['userID'];
        $user = Gdn::userModel()->getID($userID);
        if(!$user) {
            throw new NotFoundException('User');
        }

        if(!$this->groupModel->canManageMembers($group)) {
            throw new ClientException('Don\'t have permissions to add a member to this group.');
        }

        $watch = $body['watch'];
        $this->groupModel->join($group->GroupID, $user->UserID, $watch);
    }

    /**
     * Get all members of a group.
     *
     * @param int $id The ID of the group.
     * @param array $query
     * @return Data
     * @throws ClientException
     * @throws NotFoundException if the group could not be found.
     */
    public function get_members($id, array $query) {
        $this->permission();
        $in = $this->schema([
            'page:i?' => [
                'description' => 'Page number. See [Pagination](https://docs.vanillaforums.com/apiv2/#pagination).',
                'default' => 1,
                'minimum' => 1,
            ],
            'limit:i?' => [
                'description' => 'Desired number of items per page.',
                'default' => 30,
                'minimum' => 1,
                'maximum' => 100,
            ]
        ], 'in')->setDescription('The list of group members.');

        $out = $this->schema([
            ':a' => [
                'userID:i', // The ID of the user.
                'name:s', // The username of the user.
            ],
        ], 'out');

        $group = $this->groupModel->getID($id, DATASET_TYPE_ARRAY);
        if (!$group) {
            throw new NotFoundException('Group');
        }

        $query = $in->validate($query);
        list($offset, $limit) = offsetLimit("p{$query['page']}", $query['limit']);
        $records =  $this->groupModel->getMembers($id, [], '',$limit, $offset );
        $result = $out->validate($records);
        $paging = ApiUtils::morePagerInfo($result, '/api/v2/groups/'.$id.'/members', $query, $in);

        return new Data($result, ['paging' => $paging]);
    }

    /**
     * Archive a group.
     *
     * @param int $id The ID of the group.
     * @throws NotFoundException if the group could not be found.
     * @throws ServerException If the group could not be archived.
     * @return
     */
    public function put_archive($id) {
        $this->permission('Groups.Group.Archive');
        $this->idParamSchema();

        $group = $this->groupModel->getByGroupID($id);
        if(!$group) {
            throw new NotFoundException('Group');
        }

        if(!$this->groupModel->canArchiveGroup($group)) {
            throw new ClientException('Don\'t have permissions to archive this group.');
        }
        $this->groupModel->archiveGroup($group->GroupID, 1);
    }

    /**
     * Unarchive a group.
     *
     * @param int $id The ID of the group.
     * @throws NotFoundException if the group could not be found.
     * @throws ServerException If the group could not be archived.
     * @return
     */
    public function put_unarchive($id) {
        $this->permission('Groups.Group.Archive');
        $this->idParamSchema();

        $group = $this->groupModel->getByGroupID($id);
        if(!$group) {
            throw new NotFoundException('Group');
        }

        if(!$this->groupModel->canArchiveGroup($group)) {
            throw new ClientException('Don\'t have permissions to unarchive this group.');
        }
        $this->groupModel->archiveGroup($group->GroupID, 0);
    }

    /**
     * Remove a member from a group
     *
     * @param int $id The groupID of the group
     * @param int $userid The Vanilla User ID of the user
     * @throws NotFoundException if the group or user could not be found.
     */
    public function delete_member($id, $userid) {
        $this->idUserIdParamSchema()->setDescription('Remove a member from a group.');
        $this->schema([], 'out');

        $group = $this->groupModel->getByGroupID($id);
        if(!$group) {
            throw new NotFoundException('Group');
        }

        $user = Gdn::userModel()->getID($userid);
        if(!$user) {
            throw new NotFoundException('User');
        }

       if(!$this->groupModel->canRemoveMember($group)) {
            throw new ClientException('Don\'t have permissions to remove this member from the group.');
       }

        $this->groupModel->removeMember($group->GroupID, $user->UserID);
    }

    /**
     * Update watch status for a member
     *
     * @param int $id The groupID of the group
     * @param int $userid The Vanilla User ID of the user
     * @throws NotFoundException if the group or user could not be found.
     */
    public function patch_member($id, $userid, array $body) {
        $this->permission('Groups.Group.Edit');
        $in = $this->groupMemberPatchSchema();
        $user = Gdn::userModel()->getID($userid);
        if(!$user) {
            throw new NotFoundException('User');
        }

        $group = $this->groupModel->getByGroupID($id);
        if(!$group) {
            throw new NotFoundException('Group');
        }

        $isMember = GroupModel::isMemberOfGroup($user->UserID, $group->GroupID);
        if(!$isMember) {
            throw new ClientException('User is not a member of this group');
        }

        $body = $in->validate($body);
        if(!array_key_exists('watch', $body)) {
            throw new ClientException('At least one parameter must be set');
        }

        if(array_key_exists('watch', $body)) {
            $watch = $body['watch'];
            $this->groupModel->watchGroup($group, $user->UserID, $watch);
        }
    }

    /**
     * Get Member details
     *
     * @param int $id The groupID of the group
     * @param int $userid The Vanilla User ID of the user
     * @throws NotFoundException if the group or user could not be found.
     */
    public function get_member($id, $userid) {
        $this->permission();
        $user = Gdn::userModel()->getID($userid);
        if(!$user) {
            throw new NotFoundException('User');
        }

        $group = $this->groupModel->getByGroupID($id);
        if(!$group) {
            throw new NotFoundException('Group');
        }

        $isMember = GroupModel::isMemberOfGroup($user->UserID, $group->GroupID);
        if(!$isMember) {
            throw new ClientException('User is not a member of this group');
        }

        $hasWatched = $this->groupModel->hasWatchedGroup($group, $user->UserID);
        $unreadNotifications = $this->groupModel->getUnreadNotifications($group, $user->UserID);
        $record = ['userID' => $user->UserID, 'watch' => $hasWatched , 'unreadNotifications' => $unreadNotifications];
        $out = $this->schema($this->groupMemberDetailsSchema('out'));
        $result = $out->validate($record);
        return $result;
    }

    /**
     * Get a post schema with minimal add/edit fields.
     *
     * @param string $type The type of schema.
     * @return Schema Returns a schema object.
     */
    public function groupMemberPostSchema($type) {
        if ($this->groupMemberPostSchema === null) {
            $this->groupMemberPostSchema = $this->schema(
                Schema::parse([
                    'userID:i?' => 'The userID.',
                    'watch:b?' => 'Watch all group categories'
                ]),
                'GroupMemberPost'
            );
        }
        return $this->schema($this->groupMemberPostSchema, $type);
     }

    /**
     * Get a Member Details schema.
     *
     * @param string $type The type of schema.
     * @return Schema Returns a schema object.
     */
    public function groupMemberDetailsSchema($type) {
        if ($this->groupMemberDetailsSchema === null) {
            $this->groupMemberDetailsSchema = $this->schema(
                Schema::parse([
                    'userID:i' => 'The userID.',
                    'watch:b' => 'Watch status',
                    'unreadNotifications:i' => 'Count of unread notifications'
                ]),
                'GroupMemberDetails'
            );
        }
        return $this->schema($this->groupMemberDetailsSchema, $type);
    }

    /**
     * Get Group Member Patch schema.
     *
     * @param string $type The type of schema.
     * @return Schema Returns a schema object.
     */
    public function groupMemberPatchSchema() {
        return $this->schema(
                ['watch:b' => 'Watch status'], 'in');
    }

    /**
     * Get Group Archive schema.
     *
     * @param string $type The type of schema.
     * @return Schema Returns a schema object.
     */
    public function groupArchiveSchema() {
        return $this->schema(
            ['archived:b' => 'Archived status'], 'in');
    }

    /**
     * Get a GroupID/UserID - only conversation record schema.
     *
     * @return Schema Returns a schema object.
     */
    private function idParamSchema() {
        return $this->schema(['id:i' => 'The group ID.', 'userid:i' => 'The user ID.'], 'in');
    }

    /**
     * Get a GroupID/UserID -only conversation record schema.
     *
     * @return Schema Returns a schema object.
     */
    private function idUserIdParamSchema() {
        return $this->schema(['id:i' => 'The group ID.', 'userid:i' => 'The user ID.'], 'in');
    }


    /**
     * Get the full group schema.
     *
     * @param string $type The type of schema.
     * @return Schema Returns a schema object.
     */
    public function groupSchema($type = '') {
        if ($this->groupSchema === null) {
            $this->groupSchema = $this->schema($this->fullSchema(), 'Group');
        }
        return $this->schema($this->groupSchema, $type);
    }

    /**
     * Get a group schema with minimal add fields.
     *
     * @param string $type The type of schema.
     * @return Schema Returns a schema object.
     */
    public function groupPostSchema() {
      return  $this->schema(Schema::parse([
            'name:s' => 'The name of the group.',
            'type:s' =>             [
              'enum' => [GroupModel::TYPE_CHALLENGE, GroupModel::TYPE_REGULAR],
              'description' => 'Type of the group'],
            'privacy:s' =>             [
                'enum' => [GroupModel::PRIVACY_SECRET, GroupModel::PRIVACY_PUBLIC, GroupModel::PRIVACY_PRIVATE],
                'description' => 'Privacy of the group'],
            'description:s' => 'Description of the group',
            'archived:b' => 'The archived state of the group',
            'challengeID:s?' => 'The challengeID of the Topcoder challenge.',
            'challengeUrl:s?' => 'The challengeUrl of the Topcoder challenge.',
        ]), 'GroupPost');
    }

    /**
     * Get a group schema with minimal edit fields.
     *
     * @param string $type The type of schema.
     * @return Schema Returns a schema object.
     */
    public function groupPatchSchema() {
        return  $this->schema(Schema::parse([
            'name:s?' => 'The name of the group.',
            'archived:i?' => 'The archived status of the group.',
        ]), 'GroupPatch');
    }

    /**
     * Get a schema instance comprised of all available group fields.
     *
     * @return Schema Returns a schema object.
     */
    protected function fullSchema() {
        return Schema::parse([
            'groupID:i' => 'The ID of the group.',
            'type:s' => 'Type of the group',
            'privacy:s' => 'Privacy of the group',
            'archived:i' => 'Archived status of the group',
            'name:s' => 'The name of the group.',
            'challengeID:s?' => 'The challengeID of the Topcoder challenge.',
            'challengeUrl:s?' => 'The challengeUrl of the Topcoder challenge.',
        ]);
    }
}