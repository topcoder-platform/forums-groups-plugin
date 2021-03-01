<?php if (!defined('APPLICATION')) exit(); ?>
<h1><?php echo t('Invite User'); ?></h1>
<?php
$error = $this->data('ErrorMessage');
?>
<?php
echo '<div class="Messages Errors"><ul><li>'.$error.'</li></ul></div>';
?>
