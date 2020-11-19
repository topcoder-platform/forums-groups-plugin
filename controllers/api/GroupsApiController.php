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

    /** @var Schema */
    private $groupSchema;

    /** @var Schema */
    private $groupPostSchema;

    /** @var Schema */
    private $groupMemberPostSchema;
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
        return $group;
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

        $this->groupModel->join($group->GroupID, $user->UserID);
    }

    /**
     * Archive a group.
     *
     * @param int $id The ID of the group.
     * @throws NotFoundException if the group could not be found.
     * @throws ServerException If the group could not be archived.
     * @return
     */
    public function put_archive($id, array $body) {
        $this->idParamSchema();

        $group = $this->groupModel->getByGroupID($id);
        if(!$group) {
            throw new NotFoundException('Group');
        }

        if(!$this->groupModel->canArchiveGroup($group)) {
            throw new ClientException('Don\'t have permissions to archive this group.');
        }

        $this->groupModel->archiveGroup($group->GroupID);
    }


    /**
     * Remove a member from a group
     *
     * @param int $id The groupID of the group
     * @param int $userid The Vanilla User ID of the user
     * @throws NotFoundException if the group or user could not be found.
     */
    public function delete_members($id, $userid) {

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
                ]),
                'GroupMemberPost'
            );
        }
        return $this->schema($this->groupMemberPostSchema, $type);
     }

    /**
     * Get a GroupID/UserID -only conversation record schema.
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