<?php use Vanilla\Formatting\DateTimeFormatter;
use Vanilla\Formatting\Formats\MarkdownFormat;

if (!defined('APPLICATION')) exit();

if (!function_exists('allMembersUrl')) {
    /**
     * Return a URL for a group.
     * @return string
     */
    function allMembersUrl($group, $page = '', $withDomain = true) {
        $group = (object)$group;
        $result = '/group/members/'.$group->GroupID;

        if ($page) {
            if ($page > 1 || Gdn::session()->UserID) {
                $result .= '/p'.$page;
            }
        }

        return url($result, $withDomain);
    }
}
if(!function_exists('getRoleInGroupForCurrentUser')) {
    function getRoleInGroupForCurrentUser($groupId, $groups = null) {
        if ($groups == null) {
            $groupRole = GroupModel::getGroupRoleFor(Gdn::session()->UserID, $groupId);
            return $groupRole? $groupRole : null;
        }
        return null;
    }
}

if (!function_exists('watchGroupButton')) {
    /**
     *
     * Writes the Watch/watching button
     *
     * @param int $categoryID
     * @return string
     */
    function watchGroupButton($group) {
        $groupModel = new GroupModel();
        $canWatch = $groupModel->canWatchGroup($group);
        $hasWatched = $groupModel->hasWatchedGroup($group);
        $groupID = $group->GroupID;
        $output = '';
        $text = $hasWatched ? t('Stop watching the group') : t('Watch the group');

        if($canWatch && !$hasWatched) {
            $icon = watchIcon($hasWatched, $text);
            $output .= anchor(
                $icon,
                "/group/watch/{$groupID}/" . Gdn::session()->transientKey(),
                'Hijack watchButton' . ($hasWatched ? ' isWatching' : ''),
                ['title' => $text, 'aria-pressed' => $hasWatched ? 'true' : 'false', 'role' => 'button', 'tabindex' => '0']
            );

        } else if($hasWatched) {
            $icon = watchIcon($hasWatched,$text);
            $output .= anchor(
                $icon,
                "/group/unwatch/{$groupID}/" . Gdn::session()->transientKey(),
                'Hijack watchButton' . ($hasWatched ? ' isWatching' : ''),
                ['title' => $text, 'aria-pressed' => $hasWatched ? 'true' : 'false', 'role' => 'button', 'tabindex' => '0']
            );
        }

        return $output;
    }
}

if (!function_exists('getGroupOptionsDropdown')) {
    /**
     * Constructs an options dropdown menu for a group.
     *
     * @param object|array|null $group The group to get the dropdown options for.
     * @return DropdownModule A dropdown consisting of discussion options.
     */
    function getGroupOptionsDropdown($group = null) {
        $dropdown = new DropdownModule('dropdown', '', 'OptionsMenu');
        $sender = Gdn::controller();
        $groupModel = new GroupModel();
        // $currentTopcoderProjectRoles = Gdn::controller()->data('ChallengeCurrentUserProjectRoles');
        // $groupModel->setCurrentUserTopcoderProjectRoles($currentTopcoderProjectRoles);
        if ($group == null) {
            $group = $sender->data('Group');
        }

        $groupID = $group->GroupID;
        $canEdit = $groupModel->canEdit($group) ;
        $canDelete = $groupModel->canDelete($group) ;
        $canLeave = $groupModel->canLeave($group);
        $canInviteMember = $groupModel->canInviteNewMember($group);
       // $canManageMembers = $groupModel->canManageMembers($group);
        $canManageCategories = $groupModel->canManageCategories($group);
        $canFollow = boolval(c('Vanilla.EnableCategoryFollowing')) && $groupModel->canFollowGroup($group);
        $canWatch = $groupModel->canWatchGroup($group);
        $hasFollowed = boolval(c('Vanilla.EnableCategoryFollowing')) && $groupModel->hasFollowedGroup($group);
        $hasWatched = $groupModel->hasWatchedGroup($group);
        $dropdown
            ->addLinkIf($canEdit, t('Edit'), '/group/edit/'.$groupID, 'edit')
            ->addLinkIf($canLeave, t('Leave'), '/group/leave/'.$groupID, 'leave', 'LeaveGroup Popup')
            ->addLinkIf($canDelete, t('Delete'), '/group/delete?groupid='.$groupID, 'delete', 'DeleteGroup Popup')
            ->addLinkIf($canManageCategories, t('Add Category'), '/group/category/'.$groupID, 'add_category', 'AddCategory Popup')
            ->addLinkIf($canInviteMember, t('Invite Member'), '/group/invite/'.$groupID, 'invite','InviteGroup Popup')
           // ->addLinkIf($canManageMembers, t('Manage Members'), '/group/members/'.$groupID, 'manage')
            ->addLinkIf($canFollow && !$hasFollowed, t('Follow Categories'), '/group/follow/'.$groupID, 'follow','FollowGroup Popup')
            ->addLinkIf($hasFollowed, t('Unfollow Categories'), '/group/unfollow/'.$groupID, 'unfollow', 'UnfollowGroup Popup')
           // ->addLinkIf($canWatch && !$hasWatched, t('Watch Categories'), '/group/watch/'.$groupID, 'watch','WatchGroup Popup')
          //  ->addLinkIf($hasWatched, t('Unwatch Categories'), '/group/unwatch/'.$groupID, 'unwatch', 'UnwatchGroup Popup')
        ;
        // Allow plugins to edit the dropdown.
        $sender->EventArguments['GroupOptionsDropdown'] = &$dropdown;
        $sender->EventArguments['Group'] = $group;
        $sender->fireEvent('GroupOptionsDropdown');
       return $dropdown;
    }
}


if (!function_exists('writeGroupMembers')) {
    /**
     * Return URLs for group users separated by comma.
     * @return string
     */
    function writeGroupMembers($members, $separator =',') {
        for ($i = 0; $i < count($members); $i++) {
            echo userAnchor($members[$i], 'Username');
            echo  $i != count($members)-1? $separator.' ': '';
        }
    }
}

if (!function_exists('writeGroupMembersWithPhoto')) {
    /**
     * Return URLs for group members.
     * @return string
     */
    function writeGroupMembersWithPhoto($members) {
        foreach ($members as $member) {
            echo userPhoto($member, 'Username');
        }
    }
}

if (!function_exists('writeGroupMembersWithDetails')) {
    /**
     * Return a group member details.
     * @return string
     */
    function writeGroupMembersWithDetails($members, $group) {
        $groupModel = new GroupModel();
        //  $currentTopcoderProjectRoles = Gdn::controller()->data('ChallengeCurrentUserProjectRoles');
        //  $groupModel->setCurrentUserTopcoderProjectRoles($currentTopcoderProjectRoles);

        foreach ($members as $member) {
            $memberObj = (object)$member;
            $memberID= val('UserID', $memberObj);
            $ownerID= $group->OwnerID;
            $groupID = $group->GroupID;
            $role = val('Role', $memberObj);
            $dateInserted = val('DateInserted', $memberObj);
            ?>
            <li id="Member_<?php echo $memberID?>" class="Item  hasPhotoWrap">
                <?php  echo userPhoto($member, 'PhotoWrap ProfilePhotoMedium');
                /*
                if($groupModel->canChangeGroupRole($group)) {

                    echo '<span class="Options">';
                    echo '<div class="Buttons ">';
                    if($memberID != $ownerID) {
                        if ($role === GroupModel::ROLE_LEADER) {
                            echo anchor('Make Member', '/group/setrole/' . $groupID . '?role=' . GroupModel::ROLE_MEMBER . '&memberid=' . $memberID, 'Button MakeMember', '');
                        } else {
                            echo anchor('Make Leader', '/group/setrole/' . $groupID . '?role=' . GroupModel::ROLE_LEADER . '&memberid=' . $memberID, 'Button MakeLeader', '');
                        }
                        echo anchor('Remove', '/group/removemember/' . $groupID . '?memberid=' . $memberID, 'Button DeleteGroupMember', '');
                    }
                    echo '</div>';
                    echo '</span>';
                    }
                */
                        ?>
                <div class="ItemContent">
                    <div class="Title" role="heading" aria-level="3">
                        <?php  echo userAnchor($member, 'Username'); ?>
                    </div>
                <div class="Excerpt "></div>
                <div class="Meta">
                    <span class="MItem JoinDate">Joined <time title="<?php echo $dateInserted;?>" datetime="<?php echo $dateInserted;?>"><?php echo $dateInserted;?></time></span>
                </div>
            </div>
        </li>
<?php
        }
    }
}

if (!function_exists('getImagePath')) {
    function getImagePath($imagePath) {
        // Image was uploaded to Filestack
        if (strpos($imagePath, 'http') === 0) {
            return $imagePath;
        }
        return  '/uploads/'.$imagePath;
    }
}

if (!function_exists('writeGroupIcon')) {
    function writeGroupIcon($group, $linkCssClass, $imageCssClass) {
        $groupUrl = groupUrl($group);
        if ($group->Icon) {
            $iconUrl = getImagePath($group->Icon);
            echo anchor(
                img($iconUrl, ['class' => $imageCssClass, 'aria-hidden' => 'true']),
                $groupUrl, $linkCssClass);
        }
    }
}

if (!function_exists('writeGroupBanner')) {
     function writeGroupBanner($group) {
       if($group->Banner) {
          $bannerUrl = getImagePath($group->Banner);
          echo  '<div class="Group-Banner" style="background-image: url('.$bannerUrl.')"></div>';
        }
     }
}

if (!function_exists('writeGroupHeader')) {
    function writeGroupHeader($group, $showDetails = false, $owner = null, $leaders = null, $totalMembers = null) {
        $bannerCssClass = $group->Banner ? 'HasBanner':'NoBanner';
     ?>
        <div class="Group-Header <?php echo $bannerCssClass; ?>">
            <div class="GroupOptions OptionsMenu ButtonGroup">
                <?php
                echo watchGroupButton($group);
                echo getGroupOptionsDropdown();
                ?>
            </div>
            <h1 class="Group-Title"><?php echo $group->Name; ?></h1>
            <?php echo writeGroupBanner($group);?>
            <?php if($group->Icon) { ?>
                <div class="Photo PhotoWrap PhotoWrapLarge Group-Icon-Big-Wrap">
                    <?php echo writeGroupIcon($group, '', 'Group-Icon Group-Icon-Big');?>
                </div>
            <?php }?>
            <div class="Group-Info">
                <?php if(!$group->Archived) { ?>
                <div class="Group-Description"><?php
                     $isMarkdownFormatter = strcasecmp(Gdn_Format::defaultFormat(), MarkdownFormat::FORMAT_KEY) === 0;
                     $text = $isMarkdownFormatter? Gdn_Format::to($group->Description,  MarkdownFormat::FORMAT_KEY):
                          $group->Description;
                     echo $text ;
                    ?></div>
            <?php } else {?>
                <div class="Group-Archived"><?php
                    $challengeAnchor  = $group->ChallengeUrl? anchor('challenge', $group->ChallengeUrl,'', ['target' => 'blank']):'challenge';
                    echo '<span class="Archived">Archived</span><span>This '.$challengeAnchor.' forum has been archived.</span> ';
                    ?>
                </div>
           <?php } ?>
            </div>
        </div>

        <?php
    }
}
if (!function_exists('writeGroupMetaData')) {
    function writeGroupMetaData($group, $owner = null, $copilots=null) {
    ?>
    <div class="Group-Footer">
        <div class="Group-Info">
            <div class="Meta Group-Meta Table">
                <div class="MItem TableRow">
                    <div class="TableCell Cell1">
                        <span class="MLabel">Author</span>
                        <span class="MValue"><?php echo userAnchor($owner, 'Username');?></span>
                    </div>
                    <div class="TableCell Cell2">
                        <span class="MLabel">Copilot</span>
                        <span class="MValue">
                            <?php
                                $copilotAnchors = [];
                                foreach ($copilots as $copilot) {
                                    $user = Gdn::userModel()->getByUsername($copilot);
                                    array_push($copilotAnchors, userAnchor($user, null, ['HideRoles'=> true]));
                                }
                                echo implode(', ', $copilotAnchors);
                            ?>
                        </span>
                    </div>
                </div>
                <div class="MItem TableRow Last">
                    <div class="TableCell Cell1">
                        <span class="MLabel">Privacy</span>
                        <span class="MValue"><?php  echo  $group->Privacy; ?></span>
                    </div>
                    <div class="TableCell Cell2">
                        <span class="MLabel">Date Created</span>
                        <span class="MValue"><?php
                            $dateFormatted = Gdn::getContainer()->get(DateTimeFormatter::class)->formatDate($group->DateInserted, false, '%d %B %Y');
                            $timeFormatted = Gdn::getContainer()->get(DateTimeFormatter::class)->formatDate($group->DateInserted, false, '%I:%M %p');
                            echo $dateFormatted.', '.$timeFormatted;
                            ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
        <?php
    }
}



