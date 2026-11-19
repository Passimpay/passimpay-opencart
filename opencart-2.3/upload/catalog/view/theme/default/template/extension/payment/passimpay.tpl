<div class="buttons">
  <div class="pull-right">
    <p><?php echo $text_redirect_notice; ?></p>
    <a href="<?php echo $confirm_url; ?>" id="button-confirm" class="btn btn-primary" data-loading-text="<?php echo $text_loading; ?>"><?php echo $button_confirm; ?></a>
  </div>
</div>
<script type="text/javascript"><!--
$('#button-confirm').on('click', function() {
  $(this).button('loading');
});
//--></script>
