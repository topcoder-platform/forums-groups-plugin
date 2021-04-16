<?php if (!defined('APPLICATION')) exit();
include_once $this->fetchViewLocation('helper_functions');


if(!function_exists('writeDiscussion')) {
   include_once Gdn::controller()->fetchViewLocation('helper_functions', 'discussions', 'vanilla');
}

$Session = Gdn::session();
$Group = $this->data('Group');
$Owner = Gdn::userModel()->getID($Group->OwnerID);
// $Leaders = $this->data('Leaders');
// $Members = $this->data('Members');
$Copilots = $this->data('Copilots');
$Discussions = $this->data('Discussions');
$Announcements = $this->data('Announcements');
// $TotalMembers = $this->data('TotalMembers');
$bannerCssClass = $Group->Banner ? 'HasBanner':'NoBanner';
$groupModel = new GroupModel();
$currentTopcoderProjectRoles = Gdn::controller()->data('ChallengeCurrentUserProjectRoles');
//$groupModel->setCurrentUserTopcoderProjectRoles($currentTopcoderProjectRoles);

?>
<?php echo writeGroupHeader($Group, true, $Owner);?>

<div class="Group-Content">
    <div class="Group-Box Group-Announcements Section-DiscussionList">
        <h1 class="H">Announcements</h1>
        <div class="PageControls">
            <div class="Button-Controls">
                <?php

                if($groupModel->canAddNewAnnouncement($Group)) {
                   echo anchor('New Announcement', $this->data('DefaultAnnouncementUrl'), 'Button Primary', '');
                }
                ?>
            </div>
        </div>
       <?php
        if (is_object($Announcements) && count($Announcements->result()) > 0) {
            echo '<ul class="DataList Discussions">';
            foreach ($Announcements->result() as $Discussion) {
                writeDiscussion($Discussion, $this, $Session);
            }
            echo '</ul>';
          } else {
        ?>
        <div class="EmptyMessage">No announcements were found.</div>
        <?php } ?>
    </div>
    <div class="Group-Box Group-Discussions Section-DiscussionList">
        <h1 class="H">Discussions</h1>
        <div class="PageControls">
            <div class="Button-Controls">
                 <?php
                 if($groupModel->canAddDiscussion($Group)) {
                     // The group category is selected automatically
                     echo anchor('New Discussion', $this->data('DefaultDiscussionUrl'), 'Button Primary', '');
                 }
                 ?>
            </div>
        </div>
        <?php
        if (is_object($Discussions) && count($Discussions->result()) > 0) {
            echo '<ul class="DataList Discussions">';
            foreach ($Discussions->result() as $Discussion) {
                writeDiscussion($Discussion, $this, $Session);
            }
            echo '</ul>'; ?>
            <?php
        } else {
            ?>
            <div class="EmptyMessage">No discussions were found.</div>
        <?php } ?>

    </div>
    <?php
       // echo '<div class="Group-Info ClearFix clearfix"><div class="Group-Box Group-MembersPreview"> <div class="PageControls">'.
       // '<h1 class="Groups H">Members</h1></div>';
       // if(count($Members) > 0 ) {
       // echo '<div class="PhotoGrid PhotoGridSmall">'
       // echo writeGroupMembersWithPhoto($Members);
       // echo anchor('All Members',allMembersUrl($this->data('Group')), 'MoreWrap');
       // echo '</div>'
       // }  else  {
       //     echo '<div class="EmptyMessage">There are no members.</div>';
       // }
       // echo '</div></div>';
    ?>
</div>
<?php echo writeGroupMetaData($Group,  $Owner, $Copilots);?>

