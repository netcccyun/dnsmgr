var currentVerificationHostnameId = '';
var currentDomainName = '6byj.cn';

$(document).ready(function(){
  $("#form-store").bootstrapValidator();
  loadFallbackOrigin();
  $("#listTable").bootstrapTable({
    url: '/cloudflare/hostnames/data/1',
    method: 'post',
    toolbar: '',
    classes: 'table table-striped table-hover table-bordered',
    uniqueId: 'id',
    responseHandler: hostnameResponseHandler,
    columns: [
      {field: 'hostname', title: '主机名'},
      {field: 'custom_origin_server', title: '自定义源站', formatter: function(v){ return v || '-'; }},
      {field: 'ssl_status', title: '证书状态', formatter: formatStatus},
      {field: 'ssl_validation_status', title: '证书校验', formatter: formatStatus},
      {field: 'verification_status', title: '所有权校验', formatter: formatStatus},
      {field: 'created_on', title: '创建时间', formatter: function(v){ return v || '-'; }},
      {field: 'validation_errors', title: '错误信息', formatter: function(v){ return v || '-'; }},
      {
        field: 'action',
        title: '操作',
        formatter: function(value, row){
          return ''
            + '<a href="javascript:openEditDialog(\''+row.id+'\')" class="btn btn-info btn-xs">编辑</a> '
            + '<a href="javascript:openVerificationDialog(\''+row.id+'\')" class="btn btn-primary btn-xs">校验</a> '
            + '<a href="javascript:deleteHostname(\''+row.id+'\', \''+htmlEscape(row.hostname)+'\')" class="btn btn-danger btn-xs">删除</a>';
        }
      }
    ]
  });
});

function hostnameResponseHandler(res){
  if(res.code !== 0){
    layer.alert(res.msg || '获取自定义主机名失败', {icon: 2});
    return {total: 0, rows: []};
  }
  return res;
}

function refreshHostnameList(){
  $("#listTable").bootstrapTable('refresh');
}

function formatStatus(value){
  var v = String(value || '').toLowerCase();
  if(v === 'active' || v === 'active_deployed' || v === 'valid'){
    return '<span class="label label-success">'+htmlEscape(value)+'</span>';
  }
  if(v === 'pending' || v === 'pending_validation' || v === 'initializing' || v === 'in_progress'){
    return '<span class="label label-warning">'+htmlEscape(value || '-')+'</span>';
  }
  if(v && v !== '-'){
    return '<span class="label label-danger">'+htmlEscape(value)+'</span>';
  }
  return '-';
}

function getHostnameRow(id){
  var row = $("#listTable").bootstrapTable('getRowByUniqueId', id);
  if(!row){
    layer.alert('未找到自定义主机名数据，请先刷新列表后重试', {icon: 2});
    return null;
  }
  return row;
}

function resetHostnameForm(){
  $("#form-store")[0].reset();
  $("#form-store input[name=hostname_id]").val('');
  $("#form-store input[name=hostname]").prop('readonly', false);
  $("#form-store").data("bootstrapValidator").resetForm(true);
}

function openAddDialog(){
  resetHostnameForm();
  $("#storeTitle").text('添加自定义主机名');
  $("#hostnameHint").text('创建后主机名不能直接改名，如需改名请删除后重建。');
  $("#modal-store").modal('show');
}

function openEditDialog(id){
  var row = getHostnameRow(id);
  if(!row){
    return;
  }
  resetHostnameForm();
  $("#storeTitle").text('编辑自定义主机名');
  $("#hostnameHint").text('主机名不可直接改名，当前仅支持修改或清空自定义源站。');
  $("#form-store input[name=hostname_id]").val(row.id);
  $("#form-store input[name=hostname]").val(row.hostname).prop('readonly', true);
  $("#form-store input[name=custom_origin_server]").val(row.custom_origin_server || '');
  $("#modal-store").modal('show');
}

function submitHostname(){
  $("#form-store").data("bootstrapValidator").validate();
  if(!$("#form-store").data("bootstrapValidator").isValid()){
    return;
  }
  var hostnameId = $.trim($("#form-store input[name=hostname_id]").val());
  var url = hostnameId ? '/cloudflare/hostnames/update/1' : '/cloudflare/hostnames/add/1';
  var successMsg = hostnameId ? '更新自定义主机名成功' : '创建自定义主机名成功';
  var ii = layer.load(2);
  $.ajax({
    type: 'POST',
    url: url,
    data: $("#form-store").serialize(),
    dataType: 'json',
    success: function(res){
      layer.close(ii);
      if(res.code === 0){
        $("#modal-store").modal('hide');
        layer.msg(res.msg || successMsg, {icon: 1, time: 1200});
        if(res.data && res.data.id){
          $("#listTable").bootstrapTable('updateByUniqueId', {id: res.data.id, row: res.data});
          if(!$("#listTable").bootstrapTable('getRowByUniqueId', res.data.id)){
            refreshHostnameList();
          }
        }else{
          refreshHostnameList();
        }
      }else{
        layer.alert(res.msg, {icon: 2});
      }
    },
    error: function(){
      layer.close(ii);
      layer.alert('服务器错误', {icon: 2});
    }
  });
}

function openVerificationDialog(id){
  var row = getHostnameRow(id);
  if(!row){
    return;
  }
  currentVerificationHostnameId = id;
  renderVerificationDialog(row);
  $("#modal-verification").modal('show');
}

function refreshHostnameValidation(){
  if(!currentVerificationHostnameId){
    layer.msg('请先选择自定义主机名');
    return;
  }
  var ii = layer.load(2);
  $.ajax({
    type: 'POST',
    url: '/cloudflare/hostnames/refresh/1',
    data: {hostname_id: currentVerificationHostnameId},
    dataType: 'json',
    success: function(res){
      layer.close(ii);
      if(res.code === 0){
        if(res.data && res.data.id){
          $("#listTable").bootstrapTable('updateByUniqueId', {id: res.data.id, row: res.data});
          renderVerificationDialog(res.data);
        }else{
          refreshHostnameList();
        }
        layer.msg(res.msg, {icon: 1, time: 1200});
      }else{
        layer.alert(res.msg, {icon: 2});
      }
    },
    error: function(){
      layer.close(ii);
      layer.alert('服务器错误', {icon: 2});
    }
  });
}

function renderVerificationDialog(row){
  $("#verificationTitle").text('证书校验 - ' + row.hostname);
  var html = '';
  html += '<div class="alert alert-info"><strong>说明：</strong> 下列值直接来自 Cloudflare 返回结果，可直接复制到 DNS、源站或验证目录中。点击“刷新校验”会重新向 Cloudflare 发起一次校验。</div>';
  html += '<div class="row">';
  html += '<div class="col-sm-4">'+renderSummaryCard('证书状态', formatStatusText(row.ssl_status))+'</div>';
  html += '<div class="col-sm-4">'+renderSummaryCard('证书校验', formatStatusText(row.ssl_validation_status))+'</div>';
  html += '<div class="col-sm-4">'+renderSummaryCard('所有权校验', formatStatusText(row.verification_status))+'</div>';
  html += '</div>';

  var ownership = row.ownership_verification || {};
  if(ownership.name || ownership.value){
    html += renderSection('所有权 TXT 校验',
      renderCopyInput('记录类型', ownership.type || 'txt', false)
      + renderCopyInput('TXT 名称', ownership.name || '', true)
      + renderCopyTextarea('TXT 值', ownership.value || '', true, 3)
      + renderQuickAddTxtButton(ownership.name || '', ownership.value || '', '快速添加所有权 TXT')
    );
  }

  var ownershipHttp = row.ownership_verification_http || {};
  if(ownershipHttp.http_url || ownershipHttp.http_body){
    html += renderSection('所有权 HTTP 校验',
      renderCopyTextarea('HTTP URL', ownershipHttp.http_url || '', true, 2)
      + renderCopyTextarea('HTTP Body', ownershipHttp.http_body || '', true, 3)
    );
  }

  var records = $.isArray(row.ssl_validation_records) ? row.ssl_validation_records : [];
  if(records.length > 0){
    var recordsHtml = '';
    for(var i = 0; i < records.length; i++){
      var item = records[i] || {};
      var emails = $.isArray(item.emails) ? item.emails.join('\n') : '';
      recordsHtml += '<div class="panel panel-default" style="margin-bottom:12px;">';
      recordsHtml += '<div class="panel-heading"><strong>证书校验记录 #' + (i + 1) + '</strong><span class="pull-right">' + formatStatusText(item.status || '-') + '</span></div>';
      recordsHtml += '<div class="panel-body">';
      recordsHtml += renderCopyInput('TXT 名称', item.txt_name || '', true);
      recordsHtml += renderCopyTextarea('TXT 值', item.txt_value || '', true, 3);
      recordsHtml += renderQuickAddTxtButton(item.txt_name || '', item.txt_value || '', '快速添加 TXT');
      recordsHtml += renderCopyInput('CNAME 名称', item.cname_name || '', true);
      recordsHtml += renderCopyTextarea('CNAME 目标', item.cname_target || '', true, 2);
      recordsHtml += renderCopyTextarea('HTTP URL', item.http_url || '', true, 2);
      recordsHtml += renderCopyTextarea('HTTP Body', item.http_body || '', true, 3);
      recordsHtml += renderCopyTextarea('邮箱地址', emails, false, 2);
      recordsHtml += '</div></div>';
    }
    html += renderSection('证书校验记录', recordsHtml);
  }else{
    html += '<div class="alert alert-warning">Cloudflare 当前尚未返回证书校验记录，请先等待状态进入 <code>pending_validation</code>，再点击“刷新校验”或稍后刷新列表。</div>';
  }

  if(row.validation_errors){
    html += renderSection('错误信息', renderCopyTextarea('错误信息', row.validation_errors, false, 3));
  }

  $("#verificationContent").html(html);
}

function renderSummaryCard(title, value){
  return '<div class="panel panel-default"><div class="panel-heading"><strong>' + htmlEscape(title) + '</strong></div><div class="panel-body">' + value + '</div></div>';
}

function renderSection(title, body){
  return '<div class="panel panel-default"><div class="panel-heading"><strong>' + htmlEscape(title) + '</strong></div><div class="panel-body">' + body + '</div></div>';
}

function renderCopyInput(label, value, copyable){
  var safeValue = String(value || '');
  if(!safeValue){
    return '';
  }
  var html = '<div class="form-group">';
  html += '<label>' + htmlEscape(label) + '</label>';
  if(copyable){
    html += '<div class="input-group">';
    html += '<input type="text" class="form-control" readonly value="' + htmlEscape(safeValue) + '">';
    html += '<span class="input-group-btn"><button type="button" class="btn btn-default" data-copy="' + encodeURIComponent(safeValue) + '" onclick="copyEncodedValue(this)">复制</button></span>';
    html += '</div>';
  }else{
    html += '<input type="text" class="form-control" readonly value="' + htmlEscape(safeValue) + '">';
  }
  html += '</div>';
  return html;
}

function renderCopyTextarea(label, value, copyable, rows){
  var safeValue = String(value || '');
  if(!safeValue){
    return '';
  }
  var html = '<div class="form-group">';
  html += '<label>' + htmlEscape(label) + '</label>';
  html += '<textarea class="form-control" rows="' + (rows || 3) + '" readonly>' + htmlEscape(safeValue) + '</textarea>';
  if(copyable){
    html += '<div class="text-right" style="margin-top:8px;"><button type="button" class="btn btn-default btn-xs" data-copy="' + encodeURIComponent(safeValue) + '" onclick="copyEncodedValue(this)">复制</button></div>';
  }
  html += '</div>';
  return html;
}

function renderQuickAddTxtButton(name, value, label){
  var txtName = String(name || '').trim();
  var txtValue = String(value || '').trim();
  if(!txtName || !txtValue){
    return '';
  }
  return '<div class="text-right" style="margin-top:8px;margin-bottom:12px;"><button type="button" class="btn btn-success btn-xs" data-name="' + encodeURIComponent(txtName) + '" data-value="' + encodeURIComponent(txtValue) + '" onclick="quickAddTxtRecord(this)">' + htmlEscape(label || '快速添加 TXT') + '</button></div>';
}

function formatStatusText(value){
  var text = value || '-';
  if(text === '-'){
    return '<span class="text-muted">-</span>';
  }
  return formatStatus(text);
}

function copyEncodedValue(btn){
  copyText(decodeURIComponent($(btn).attr('data-copy') || ''));
}

function copyText(text){
  var value = String(text || '');
  if(!value){
    layer.msg('没有可复制的内容');
    return;
  }
  if(navigator.clipboard && window.isSecureContext){
    navigator.clipboard.writeText(value).then(function(){
      layer.msg('已复制', {icon: 1, time: 1000});
    }).catch(function(){
      fallbackCopyText(value);
    });
    return;
  }
  fallbackCopyText(value);
}

function fallbackCopyText(text){
  var $temp = $('<textarea readonly></textarea>');
  $('body').append($temp);
  $temp.val(text).select();
  try{
    document.execCommand('copy');
    layer.msg('已复制', {icon: 1, time: 1000});
  }catch(e){
    layer.alert('复制失败，请手动复制', {icon: 2});
  }
  $temp.remove();
}

function quickAddTxtRecord(btn){
  var fullName = decodeURIComponent($(btn).attr('data-name') || '');
  var value = decodeURIComponent($(btn).attr('data-value') || '');
  var rr = convertFullHostnameToRecordName(fullName);
  if(rr === null){
    layer.alert('TXT 记录名称与当前域名不匹配，无法自动添加，请手动到解析页添加', {icon: 2});
    return;
  }

  layer.confirm('确定要快速添加 TXT 记录吗？<br><code>' + htmlEscape(fullName) + '</code>', {title: '提示', icon: 0}, function(){
    var ii = layer.load(2);
    $.ajax({
      type: 'POST',
      url: '/record/add/1',
      data: {
        name: rr,
        type: 'TXT',
        value: value,
        line: '0',
        ttl: 600,
        mx: 1,
        weight: 0,
        remark: 'Cloudflare证书校验'
      },
      dataType: 'json',
      success: function(res){
        layer.close(ii);
        if(res.code === 0){
          layer.closeAll();
          $("#modal-verification").modal('show');
          layer.msg('TXT 记录添加成功', {icon: 1, time: 1200});
        }else{
          layer.alert(res.msg, {icon: 2});
        }
      },
      error: function(){
        layer.close(ii);
        layer.alert('服务器错误', {icon: 2});
      }
    });
  });
}

function convertFullHostnameToRecordName(fullName){
  var name = String(fullName || '').trim().replace(/\.$/, '');
  var domain = String(currentDomainName || '').trim().replace(/\.$/, '');
  if(!name || !domain){
    return null;
  }
  var lowerName = name.toLowerCase();
  var lowerDomain = domain.toLowerCase();
  if(lowerName === lowerDomain){
    return '@';
  }
  if(lowerName.endsWith('.' + lowerDomain)){
    return name.slice(0, name.length - domain.length - 1);
  }
  if(name === '@'){
    return '@';
  }
  if(name.indexOf('.') === -1){
    return name;
  }
  return null;
}

function deleteHostname(id, hostname){
  layer.confirm('确定要删除自定义主机名 ' + hostname + ' 吗？', {title: '提示', icon: 0}, function(){
    var ii = layer.load(2);
    $.ajax({
      type: 'POST',
      url: '/cloudflare/hostnames/delete/1',
      data: {hostname_id: id, hostname: hostname},
      dataType: 'json',
      success: function(res){
        layer.close(ii);
        if(res.code === 0){
          layer.closeAll();
          layer.msg(res.msg, {icon: 1, time: 1000});
          refreshHostnameList();
        }else{
          layer.alert(res.msg, {icon: 2});
        }
      },
      error: function(){
        layer.close(ii);
        layer.alert('服务器错误', {icon: 2});
      }
    });
  });
}

function loadFallbackOrigin(){
  $.ajax({
    type: 'POST',
    url: '/cloudflare/fallback/get/1',
    dataType: 'json',
    success: function(res){
      if(res.code === 0){
        $("#fallbackOrigin").val((res.data && res.data.origin) ? res.data.origin : '');
      }else{
        layer.alert(res.msg, {icon: 2});
      }
    }
  });
}

function saveFallbackOrigin(){
  var origin = $.trim($("#fallbackOrigin").val());
  if(!origin){
    layer.msg('请输入 Fallback Origin');
    return;
  }
  var ii = layer.load(2);
  $.ajax({
    type: 'POST',
    url: '/cloudflare/fallback/set/1',
    data: {origin: origin},
    dataType: 'json',
    success: function(res){
      layer.close(ii);
      if(res.code === 0){
        $("#fallbackOrigin").val(res.data.origin || origin);
        layer.msg(res.msg, {icon: 1, time: 1200});
      }else{
        layer.alert(res.msg, {icon: 2});
      }
    },
    error: function(){
      layer.close(ii);
      layer.alert('服务器错误', {icon: 2});
    }
  });
}

function clearFallbackOrigin(){
  layer.confirm('确定要清空 Fallback Origin 吗？', {title: '提示', icon: 0}, function(){
    var ii = layer.load(2);
    $.ajax({
      type: 'POST',
      url: '/cloudflare/fallback/delete/1',
      dataType: 'json',
      success: function(res){
        layer.close(ii);
        if(res.code === 0){
          layer.closeAll();
          $("#fallbackOrigin").val('');
          layer.msg(res.msg, {icon: 1, time: 1200});
        }else{
          layer.alert(res.msg, {icon: 2});
        }
      },
      error: function(){
        layer.close(ii);
        layer.alert('服务器错误', {icon: 2});
      }
    });
  });
}

function htmlEscape(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}
