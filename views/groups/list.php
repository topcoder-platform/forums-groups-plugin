<?php if (!defined('APPLICATION')) exit();
$Session = Gdn::session();

include_once $this->fetchViewLocation('helper_functions');


echo '<div class="media-list-container Group-Box my-groups">';
        echo '<div class="PageControls">';
        echo '<h2 class="H HomepageTitle">'.$this->data('Title').'</h2>';
        echo '</div>';

    $PagerOptions = ['Wrapper' => '<span class="PagerNub">&#160;</span><div %1$s>%2$s</div>', 'RecordCount' => $this->data('CountGroups'), 'CurrentRecords' => $this->data('Groups')->numRows()];
    if ($this->data('_PagerUrl')) {
        $PagerOptions['Url'] = $this->data('_PagerUrl');
    }
    echo '<div class="PageControls">';
        PagerModule::write($PagerOptions);
    echo '</div>';

    if ($this->data('Groups')->numRows() > 0 ) {
        ?>
        <ul class="media-list DataList">
            <?php include($this->fetchViewLocation('groups')); ?>
        </ul>
        <?php

        echo '<div class="PageControls Bottom">';
        PagerModule::write($PagerOptions);
        echo '</div>';

    } else {
        ?>
        <div class="Empty"><?php echo $this->data('NoDataText'); ?></div>
    <?php
    }
echo '</div>';