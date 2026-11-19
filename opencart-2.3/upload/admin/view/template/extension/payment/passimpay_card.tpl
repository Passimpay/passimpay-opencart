<?php echo $header; ?><?php echo $column_left; ?>
<div id="content">
  <div class="page-header">
    <div class="container-fluid">
      <div class="pull-right">
        <button type="submit" form="form-passimpay-card" data-toggle="tooltip" title="<?php echo $button_save; ?>" class="btn btn-primary"><i class="fa fa-save"></i></button>
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
    <?php if (!$main_configured) { ?>
    <div class="alert alert-warning"><i class="fa fa-exclamation-circle"></i> <a href="<?php echo $main_url; ?>"><?php echo $text_main_missing; ?></a></div>
    <?php } ?>
    <div class="panel panel-default">
      <div class="panel-heading">
        <h3 class="panel-title"><i class="fa fa-pencil"></i> <?php echo $text_edit; ?></h3>
      </div>
      <div class="panel-body">
        <p><?php echo $text_uses_main; ?></p>
        <div class="alert alert-info"><i class="fa fa-info-circle"></i> <?php echo $entry_card_notice; ?></div>
        <form action="<?php echo $action; ?>" method="post" enctype="multipart/form-data" id="form-passimpay-card" class="form-horizontal">
          <div class="form-group">
            <label class="col-sm-2 control-label"><span data-toggle="tooltip" title="<?php echo $entry_title_help; ?>"><?php echo $entry_title; ?></span></label>
            <div class="col-sm-10">
              <?php foreach ($languages as $language) { ?>
              <div class="input-group">
                <span class="input-group-addon"><img src="language/<?php echo $language['code']; ?>/<?php echo $language['code']; ?>.png" title="<?php echo $language['name']; ?>" /></span>
                <input type="text" name="passimpay_card_title[<?php echo $language['language_id']; ?>]" value="<?php echo isset($passimpay_card_title[$language['language_id']]) ? htmlspecialchars(html_entity_decode($passimpay_card_title[$language['language_id']], ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8') : ''; ?>" placeholder="<?php echo $entry_title; ?>" class="form-control" />
              </div>
              <?php } ?>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label"><span data-toggle="tooltip" title="<?php echo $entry_logo_help; ?>"><?php echo $entry_logo; ?></span></label>
            <div class="col-sm-10">
              <a href="" id="thumb-passimpay-card-logo" data-toggle="image" class="img-thumbnail"><img src="<?php echo $thumb; ?>" alt="" title="" data-placeholder="<?php echo $placeholder; ?>" /></a>
              <input type="hidden" name="passimpay_card_logo" value="<?php echo $passimpay_card_logo; ?>" id="input-passimpay-card-logo" />
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label" for="input-status"><?php echo $entry_status; ?></label>
            <div class="col-sm-10">
              <select name="passimpay_card_status" id="input-status" class="form-control">
                <option value="1" <?php echo ($passimpay_card_status == 1 ? 'selected="selected"' : ''); ?>><?php echo $text_enabled; ?></option>
                <option value="0" <?php echo ($passimpay_card_status == 0 ? 'selected="selected"' : ''); ?>><?php echo $text_disabled; ?></option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label" for="input-sort"><?php echo $entry_sort_order; ?></label>
            <div class="col-sm-10">
              <input type="text" name="passimpay_card_sort_order" value="<?php echo $passimpay_card_sort_order; ?>" placeholder="<?php echo $entry_sort_order; ?>" id="input-sort" class="form-control" />
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-2 control-label" for="input-geo-zone"><?php echo $entry_geo_zone; ?></label>
            <div class="col-sm-10">
              <select name="passimpay_card_geo_zone_id" id="input-geo-zone" class="form-control">
                <option value="0"><?php echo $text_all_zones; ?></option>
                <?php foreach ($geo_zones as $gz) { ?>
                <option value="<?php echo $gz['geo_zone_id']; ?>" <?php echo ($gz['geo_zone_id'] == $passimpay_card_geo_zone_id ? 'selected="selected"' : ''); ?>><?php echo $gz['name']; ?></option>
                <?php } ?>
              </select>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php echo $footer; ?>
