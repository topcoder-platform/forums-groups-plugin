<?php use Vanilla\Formatting\DateTimeFormatter;
use Vanilla\Formatting\Formats\MarkdownFormat;

if (!defined('APPLICATION')) exit();

if (!function_exists('getGroupUrl')) :
    function getGroupUrl($group) {
        if (Gdn::session()->isValid()) {
            include_once Gdn::controller()->fetchViewLocation('helper_functions', 'group');
            return groupUrl($group);
        }
        return '';
    }
endif;

if (!function_exists('optionsList')) :
    /**
     * Build HTML for group options menu.
     *
     * @param $group
     * @return DropdownModule|string
     * @throws Exception
     */
    function optionsList($group) {
        if (Gdn::session()->isValid()) {
            include_once Gdn::controller()->fetchViewLocation('helper_functions', 'group');
            return getGroupOptionsDropdown($group);
        }
        return '';
    }
endif;

if(!function_exists('hasJoinedGroup')) {
    function hasJoinedGroup($groupID) {
        if (Gdn::session()->isValid()) {
            include_once Gdn::controller()->fetchViewLocation('helper_functions', 'group');
            return getRoleInGroupForCurrentUser($groupID);
        }
        return '';
    }
}


if(!function_exists('writeGroupIconWrap')) {
    function writeGroupIconWrap($group, $linkCssClass, $imageCssClass) {
        if (Gdn::session()->isValid()) {
            include_once Gdn::controller()->fetchViewLocation('helper_functions', 'group');
            return writeGroupIcon($group, $linkCssClass, $imageCssClass);
        }
        return '';
    }
}


if (!function_exists('writeGroups')) {
    function writeGroups($Groups, $sender){
        foreach ($Groups->result() as $Group) {
            writeGroup($Group, $sender,  Gdn::session());
        }

    }
}

if (!function_exists('writeGroup')) {

    /**
     *
     *
     * @param $group
     * @param $sender
     * @param $session
     */
    function writeGroup($group, $sender, $session) {
        $cssClass = cssClass($group);
        $groupUrl = getGroupUrl($group);
        $groupName = $group->Name;
        $isMarkdownFormatter = strcasecmp(Gdn_Format::defaultFormat(), MarkdownFormat::FORMAT_KEY) === 0;
        $groupDesc = $group->Description;
        if($isMarkdownFormatter) {
            $groupDesc = Gdn_Format::to($groupDesc, MarkdownFormat::FORMAT_KEY);
            $groupDesc = preg_replace('/<br\\s*?\/??>/i', "", $groupDesc);
        }
        $wrapCssClass = $group->Icon ? 'hasPhotoWrap':'noPhotoWrap';
        ?>
        <li id="Group_<?php echo $group->GroupID; ?>" class="<?php echo $cssClass.' '.$wrapCssClass; ?> ">
            <?php

            if (!property_exists($sender, 'CanEditGroups')) {
                // $sender->CanEditGroups = val('PermsDiscussionsEdit', CategoryModel::categories($discussion->CategoryID)) && c('Vanilla.AdminCheckboxes.Use');
            }
            ?>
            <div class="ItemContent Group">
                <div class="Options">
                    <?php
                    echo watchGroupButton($group);
                    echo getGroupOptionsDropdown($group);
                    ?>
                </div>
                <?php
                 echo writeGroupIconWrap($group, 'Item-Icon PhotoWrap PhotoWrap-Category','GroupPhoto');
                 ?>
                <div class="Title" role="heading" class="TitleWrap">
                    <?php echo anchor($groupName, $groupUrl, 'Title'); ?>
                </div>
                <div class="Description">
                    <?php echo $groupDesc;  ?>
                </div>
                <div class="Meta Meta-Group GroupLastDiscussionComment">
                    <?php
                    $last = userBuilder($group, 'LastDiscussionCommentsUser');
                    $lastDate = val('LastDiscussionCommentsDate', $group);
                    if ($group->LastDiscussionCommentsUserUserID != '') {
                        $dateFormatted = Gdn::getContainer()->get(DateTimeFormatter::class)->formatDate($lastDate, false, '%a, %b %e %Y');
                        $timeFormatted = Gdn::getContainer()->get(DateTimeFormatter::class)->formatDate($lastDate, false, '%I:%M %p');
                        echo '<span class="MItem LastCommentBy">'.sprintf(t('Most recent by %1$s on %2$s at %3$s'), userAnchor($last),$dateFormatted, $timeFormatted).'</span>';
                    }
                    ?>
                    <span class="MItem fill-remaining-space"></span>
                    <span class="MItem MCount ViewCount"><?php
                        printf(pluralTranslate($group->CountAllDiscussions,
                            '%s view html', '%s views html', t('%s discussion'), t('%s discussions')),
                            bigPlural($group->CountAllDiscussions, '%s discussions'));
                        ?></span>
                    <span class="MItem MiddleDot">&#183;</span>
                    <span class="MItem MCount CommentCount"><?php
                        printf(pluralTranslate($group->CountAllComments,
                            '%s comment html', '%s comments html', t('%s comment'), t('%s comments')),
                            bigPlural($group->CountAllComments, '%s comments'));
                        ?></span>
                 </div>
            </div>
        </li>
    <?php
    }

}

if(!function_exists('buildGroupPagerOptions')) {
    function buildGroupPagerOptions($Groups, $Pager){
        $pagerOptions = ['Wrapper' => '<span class="PagerNub">&#160;</span><div %1$s>%2$s</div>', 'RecordCount' => $Pager->TotalRecords,
            'CurrentRecords' => $Groups->numRows()];

        return $pagerOptions;
    }
}

if(!function_exists('writeGroupSection')) {

    function writeGroupSection($Groups, $Pager = null, $sectionTitle, $noDataText, $moreDataText, $moreDataLink, $sender){
        if($Pager) {
            $PagerOptions = buildGroupPagerOptions($Groups, $Pager);
            PagerModule::current($Pager);
        }

        if ($Groups->numRows() > 0 ) {  ?>
            <?php
            if($Pager) {
                echo '<div class="PageControls">';
                PagerModule::write($PagerOptions);
                echo '</div>';
            }
            ?>
            <ul class="DataList GroupList">
                <?php echo writeGroups($Groups, $sender); ?>
            </ul>
            <?php
                if($Pager) {
                    echo '<div class="PageControls Bottom">';
                    PagerModule::write($PagerOptions);
                    echo '</div>';
                } else { ?>
                    <div class="MoreWrap"> <?php echo anchor($moreDataText, $moreDataLink, 'MoreWrap');?></div>
                <?php
                }
            ?>
            <?php
        } else {
            ?>
            <div class="Empty"><?php echo $noDataText; ?></div>
            <?php
        }
    }
}