<?php if (!defined('APPLICATION')) exit(); ?>
<h1><?php echo t('Delete'); ?></h1>
<?php
echo $this->Form->open();
echo $this->Form->errors();
?>
<div class="Wrap">
    <div class="P">Are you sure you want to delete this item?</div>
    <?php
    echo '<div class="Buttons Buttons-Confirm">';
    echo $this->Form->button('Cancel', ['type' => 'button', 'class' => 'Button Close']);
    echo $this->Form->button( 'Delete', ['class' => 'Button Primary Delete']);
    echo '</div>';

    ?>
</div>