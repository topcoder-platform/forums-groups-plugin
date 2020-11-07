<?php if (!defined('APPLICATION')) exit(); ?>
<h1><?php echo t('Add New Category'); ?></h1>
<?php
$Group = $this->data('Group');
echo $this->Form->open();
echo $this->Form->errors();
?>
<div class="Wrap">
    <?php
    echo '<div class="P">Are you sure you want to add a new category to this \''. $Group->Name.'\' group?</div>';
    echo '<div class="P">';
    echo $this->Form->label('Category Name', 'Name');
    echo wrap($this->Form->textBox('Name', ['maxlength' => 255, 'class' => 'InputBox']), 'div', ['class' => 'TextBoxWrapper']);
    echo '</div>';
    echo '<div class="Buttons Buttons-Confirm">';
    echo $this->Form->button('Cancel', ['type' => 'button', 'class' => 'Button Close']);
    echo $this->Form->button( 'Add Category', ['class' => 'Button Primary GroupButton']);
    echo '</div>';

    ?>
</div>