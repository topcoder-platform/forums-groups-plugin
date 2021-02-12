<?php if (!defined('APPLICATION')) exit();
$Session = Gdn::session();
include_once $this->fetchViewLocation('helper_functions');

if (!function_exists('writeDiscussion')) {
    include($this->fetchViewLocation('helper_functions', 'discussions', 'vanilla'));
}

$Session = Gdn::session();
$Group = $this->data('Group');
$Owner = Gdn::userModel()->getID($Group->OwnerID);
$Discussions = $this->data('Discussions');
$CountDiscussions = $this->data('CountDiscussions');
$bannerCssClass = $Group->Banner ? 'HasBanner':'NoBanner';
?>

<?php echo writeGroupHeader($Group);?>

<div class="Group-Content">
    <div class="Group-Box Group-Discussions Section-DiscussionList">
    <?php
        echo ' <h1 class="H clearfix">Discussions</h1>';
        echo '<div class="PageControls">';
        $PagerOptions = ['Wrapper' => '<span class="PagerNub">&#160;</span><div %1$s>%2$s</div>', 'RecordCount' => $CountDiscussions, 'CurrentRecords' => $Discussions->numRows()];
        if ($this->data('_PagerUrl')) {
            $PagerOptions['Url'] = $this->data('_PagerUrl');
        }
        PagerModule::write($PagerOptions);
        //  echo Gdn_Theme::module('NewDiscussionModule', $this->data('_NewDiscussionProperties', ['CssClass' => 'Button Action Primary']));
        // Avoid displaying in a category's list of discussions.
        if ($this->data('EnableFollowingFilter')) {
       // echo discussionFilters();
        }
        $this->fireEvent('PageControls');
        echo '</div>';

            if ($Discussions->numRows() > 0) {
            ?>
            <ul class="DataList Discussions">
                <?php
                foreach ($this->Discussions->result() as $Discussion) {
                    writeDiscussion($Discussion, $this, $Session);
                }
                ?>
            </ul>
            <?php

        echo '<div class="PageControls Bottom">';
        PagerModule::write($PagerOptions);
        echo Gdn_Theme::module('NewDiscussionModule', $this->data('_NewDiscussionProperties', ['CssClass' => 'Button Action Primary']));
        echo '</div>';

    } else {
        ?>
        <div class="Empty"><?php echo t('No discussions were found.'); ?></div>
        <?php
    }
            ?>
    </div>
</div>