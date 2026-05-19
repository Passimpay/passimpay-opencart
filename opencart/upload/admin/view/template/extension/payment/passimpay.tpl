<?php echo $header; ?><?php echo $column_left; ?>
<div id="content">
  <div class="page-header">
    <div class="container-fluid">
      <div class="pull-right">
        <button type="submit" form="form-passimpay" data-toggle="tooltip" title="<?php echo $button_save; ?>" class="btn btn-primary"><i class="fa fa-save"></i></button>
        <a href="<?php echo $cancel; ?>" data-toggle="tooltip" title="<?php echo $button_cancel; ?>" class="btn btn-default"><i class="fa fa-reply"></i></a>
      </div>
      <h1><?php echo $heading_title; ?></h1>
      <ul class="breadcrumb">
        <?php foreach ($breadcrumbs as $breadcrumb) { ?>
        <li><a href="<?php echo $breadcrumb['href']; ?>"><?php echo $breadcrumb['text']; ?></a></li>
        <?php } ?>
      </ul>
    </div>
  </div>
  <div class="container-fluid">
    <?php if ($error_warning) { ?>
    <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?php echo $error_warning; ?>
      <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
    <?php } ?>
    <div class="panel panel-default">
      <div class="panel-heading">
        <h3 class="panel-title"><i class="fa fa-pencil"></i> <?php echo $text_edit; ?></h3>
      </div>
      <div class="panel-body">
        <ul class="nav nav-tabs">
          <li class="active"><a href="#tab-general" data-toggle="tab"><?php echo $tab_general; ?></a></li>
          <li><a href="#tab-tools" data-toggle="tab"><?php echo $tab_tools; ?></a></li>
        </ul>
        <div class="tab-content">

          <div class="tab-pane active" id="tab-general">
            <form action="<?php echo $action; ?>" method="post" enctype="multipart/form-data" id="form-passimpay" class="form-horizontal">
              <div class="form-group required">
                <label class="col-sm-2 control-label" for="input-api-key"><?php echo $entry_api_key; ?></label>
                <div class="col-sm-10">
                  <input type="text" name="passimpay_api_key" value="<?php echo $passimpay_api_key; ?>" placeholder="<?php echo $entry_api_key; ?>" id="input-api-key" class="form-control" />
                  <?php if ($error_api_key) { ?><div class="text-danger"><?php echo $error_api_key; ?></div><?php } ?>
                </div>
              </div>
              <div class="form-group required">
                <label class="col-sm-2 control-label" for="input-platform-id"><?php echo $entry_platform_id; ?></label>
                <div class="col-sm-10">
                  <input type="text" name="passimpay_platform_id" value="<?php echo $passimpay_platform_id; ?>" placeholder="<?php echo $entry_platform_id; ?>" id="input-platform-id" class="form-control" />
                  <?php if ($error_platform_id) { ?><div class="text-danger"><?php echo $error_platform_id; ?></div><?php } ?>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-2 control-label" for="input-payment-type"><?php echo $entry_payment_type; ?></label>
                <div class="col-sm-10">
                  <select name="passimpay_payment_type" id="input-payment-type" class="form-control">
                    <option value="0" <?php echo ($passimpay_payment_type == 0 ? 'selected="selected"' : ''); ?>><?php echo $entry_payment_type_both; ?></option>
                    <option value="1" <?php echo ($passimpay_payment_type == 1 ? 'selected="selected"' : ''); ?>><?php echo $entry_payment_type_crypto; ?></option>
                    <option value="2" <?php echo ($passimpay_payment_type == 2 ? 'selected="selected"' : ''); ?>><?php echo $entry_payment_type_card; ?></option>
                  </select>
                  <div id="passimpay-card-notice" class="alert alert-warning" style="margin-top:10px;<?php echo ($passimpay_payment_type == 1 ? 'display:none;' : ''); ?>">
                    <i class="fa fa-info-circle"></i> <?php echo $entry_card_notice; ?>
                  </div>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-2 control-label"><?php echo $entry_callback_url; ?></label>
                <div class="col-sm-10"><code id="passimpay-callback-url"><?php echo $callback_url; ?></code></div>
              </div>
              <div class="form-group">
                <label class="col-sm-2 control-label"><?php echo $entry_return_url; ?></label>
                <div class="col-sm-10"><code><?php echo $return_url; ?></code></div>
              </div>
              <div class="form-group">
                <label class="col-sm-2 control-label" for="input-os-success"><?php echo $entry_order_status_success; ?></label>
                <div class="col-sm-10">
                  <select name="passimpay_order_status_success_id" id="input-os-success" class="form-control">
                    <?php foreach ($order_statuses as $os) { ?>
                    <option value="<?php echo $os['order_status_id']; ?>" <?php echo ($os['order_status_id'] == $passimpay_order_status_success_id ? 'selected="selected"' : ''); ?>><?php echo $os['name']; ?></option>
                    <?php } ?>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-2 control-label" for="input-os-pending"><?php echo $entry_order_status_pending; ?></label>
                <div class="col-sm-10">
                  <select name="passimpay_order_status_pending_id" id="input-os-pending" class="form-control">
                    <?php foreach ($order_statuses as $os) { ?>
                    <option value="<?php echo $os['order_status_id']; ?>" <?php echo ($os['order_status_id'] == $passimpay_order_status_pending_id ? 'selected="selected"' : ''); ?>><?php echo $os['name']; ?></option>
                    <?php } ?>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-2 control-label" for="input-os-failed"><?php echo $entry_order_status_failed; ?></label>
                <div class="col-sm-10">
                  <select name="passimpay_order_status_failed_id" id="input-os-failed" class="form-control">
                    <?php foreach ($order_statuses as $os) { ?>
                    <option value="<?php echo $os['order_status_id']; ?>" <?php echo ($os['order_status_id'] == $passimpay_order_status_failed_id ? 'selected="selected"' : ''); ?>><?php echo $os['name']; ?></option>
                    <?php } ?>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-2 control-label" for="input-status"><?php echo $entry_status; ?></label>
                <div class="col-sm-10">
                  <select name="passimpay_status" id="input-status" class="form-control">
                    <option value="1" <?php echo ($passimpay_status == 1 ? 'selected="selected"' : ''); ?>><?php echo $text_enabled; ?></option>
                    <option value="0" <?php echo ($passimpay_status == 0 ? 'selected="selected"' : ''); ?>><?php echo $text_disabled; ?></option>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-2 control-label" for="input-sort"><?php echo $entry_sort_order; ?></label>
                <div class="col-sm-10">
                  <input type="text" name="passimpay_sort_order" value="<?php echo $passimpay_sort_order; ?>" placeholder="<?php echo $entry_sort_order; ?>" id="input-sort" class="form-control" />
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-2 control-label" for="input-geo-zone"><?php echo $entry_geo_zone; ?></label>
                <div class="col-sm-10">
                  <select name="passimpay_geo_zone_id" id="input-geo-zone" class="form-control">
                    <option value="0"><?php echo $text_all_zones; ?></option>
                    <?php foreach ($geo_zones as $gz) { ?>
                    <option value="<?php echo $gz['geo_zone_id']; ?>" <?php echo ($gz['geo_zone_id'] == $passimpay_geo_zone_id ? 'selected="selected"' : ''); ?>><?php echo $gz['name']; ?></option>
                    <?php } ?>
                  </select>
                </div>
              </div>
            </form>
          </div>

          <div class="tab-pane" id="tab-tools">
            <div class="form-group">
              <p><?php echo $text_check_status_hint; ?></p>
              <label class="control-label" for="input-check-order-id"><?php echo $entry_check_status_order_id; ?></label>
              <div class="input-group" style="max-width:480px;">
                <input type="number" id="input-check-order-id" class="form-control" placeholder="123" />
                <span class="input-group-btn">
                  <button type="button" id="btn-check-status" class="btn btn-primary"><i class="fa fa-refresh"></i> <?php echo $button_check_status; ?></button>
                </span>
              </div>
              <pre id="check-status-result" style="margin-top:15px;display:none;background:#f5f5f5;padding:12px;border-radius:4px;"></pre>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>
<script type="text/javascript"><!--
(function(){
  var sel = document.getElementById('input-payment-type');
  var notice = document.getElementById('passimpay-card-notice');
  if (sel && notice) {
    sel.addEventListener('change', function(){
      var v = parseInt(this.value, 10);
      notice.style.display = (v === 0 || v === 2) ? 'block' : 'none';
    });
  }
  $('#btn-check-status').on('click', function(){
    var orderId = $('#input-check-order-id').val();
    if (!orderId) { return; }
    var $btn = $(this), $out = $('#check-status-result');
    $btn.prop('disabled', true);
    $out.show().text('...');
    $.ajax({
      url: '<?php echo $check_status_url; ?>&order_id=' + encodeURIComponent(orderId),
      type: 'GET',
      dataType: 'json',
      success: function(json){
        $out.text(JSON.stringify(json, null, 2));
      },
      error: function(xhr){
        $out.text('HTTP ' + xhr.status + ': ' + xhr.responseText);
      },
      complete: function(){
        $btn.prop('disabled', false);
      }
    });
  });
})();
//--></script>
<?php echo $footer; ?>
