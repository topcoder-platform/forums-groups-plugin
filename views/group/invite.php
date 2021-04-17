<?php if (!defined('APPLICATION')) exit(); ?>
<h1><?php echo t('Invite'); ?></h1>
<?php
$Group = $this->data('Group');
echo $this->Form->open();
echo $this->Form->errors();
?>
<div class="Wrap">
    <?php
    echo '<div class="P Message">Are you sure you want to invite User?</div>';
    echo '<div class="P">';
    echo $this->Form->label('Username', 'Username');
    echo wrap($this->Form->textBox('Username', ['maxlength' => 100, 'class' => 'InputBox BigInput']), 'div', ['class' => 'TextBoxWrapper']);
    echo '</div>';
    echo '<div class="Buttons Buttons-Confirm">';
    echo $this->Form->button('Cancel', ['type' => 'button', 'class' => 'Button Close']);
    echo $this->Form->button( 'Invite User', ['class' => 'Button Primary']);
    echo '</div>';

    ?>
</div>