var location_url = window.location.href;
var parameter_str = location_url.split('?')[1];
if (parameter_str !== undefined) {
    parameter_str = parameter_str.split('#')[0];
    var $_GET = {};
    var parameter_arr = parameter_str.split('&');
    var tmp_arr;
    for (var i = 0, len = parameter_arr.length; i <= len - 1; i++) {
        tmp_arr = parameter_arr[i].split('=');
        $_GET[tmp_arr[0]] = decodeURIComponent(tmp_arr[1]);
    }
    window.$_GET = $_GET;
} else {
    window.$_GET = [];
}

function searchRefresh(){
	$('#listTable').bootstrapTable('refresh');
	return false;
}
function searchSubmit(){
	if(typeof sidePagination != 'undefined' && sidePagination == 'client'){
		$('#listTable').bootstrapTable('refresh');
	}else{
		$('#listTable').bootstrapTable('selectPage', 1);
	}
	return false;
}
function searchClear(){
	$('#searchToolbar').find('input[name]').each(function() {
		$(this).val('');
	});
	$('#searchToolbar').find('select[name]').each(function() {
		$(this).find('option:first').prop("selected", 'selected');
	});
	if(typeof sidePagination != 'undefined' && sidePagination == 'client'){
		$('#listTable').bootstrapTable('refresh');
	}else{
		$('#listTable').bootstrapTable('selectPage', 1);
	}
}
function updateToolbar(){
    $('#searchToolbar').find(':input[name]').each(function() {
		var name = $(this).attr('name');
		if(typeof window.$_GET[name] != 'undefined')
			$(this).val(window.$_GET[name]);
	})
}
function updateQueryStr(obj){
	var arr = [];
    for (var p in obj){
		if (obj.hasOwnProperty(p) && typeof obj[p] != 'undefined' && obj[p] != '') {
			arr.push(p + "=" + encodeURIComponent(obj[p]));
		}
	}
	history.replaceState({}, null, '?'+arr.join("&"));
}

function initDomainQuickSwitch(options){
	if(typeof $ === 'undefined' || typeof $.fn.select2 === 'undefined'){
		return null;
	}
	var settings = $.extend({
		selectSelector: '#quickDomainSwitch',
		buttonSelector: '#quickDomainSwitchBtn',
		currentId: '',
		currentText: '',
		placeholder: '搜索域名后切换',
		useAjax: false,
		type: '',
		limit: 10,
		buildUrl: function(id){
			return '/record/' + id;
		}
	}, options || {});
	var $select = $(settings.selectSelector);
	if(!$select.length){
		return null;
	}
	if(settings.currentId !== '' && settings.currentText !== '' && $select.find('option[value="' + settings.currentId + '"]').length === 0){
		$select.append(new Option(settings.currentText, settings.currentId, true, true));
	}
	var select2Options = {
		width: '100%',
		language: 'zh-CN',
		placeholder: settings.placeholder
	};
	if(settings.useAjax){
		select2Options.ajax = {
			url: '/domain/data',
			type: 'post',
			dataType: 'json',
			delay: 250,
			data: function(params) {
				var page = params.page || 1;
				return {
					kw: params.term || '',
					type: settings.type || '',
					offset: settings.limit * (page - 1),
					limit: settings.limit
				};
			},
			processResults: function(data, params) {
				params.page = params.page || 1;
				var rows = $.isArray(data.rows) ? data.rows : [];
				return {
					results: $.map(rows, function(item){
						var text = item.name || '';
						if(item.typename){
							text += ' [' + item.typename + ']';
						}
						return {
							id: item.id,
							text: text
						};
					}),
					pagination: {
						more: (data.total || 0) > settings.limit * params.page
					}
				};
			},
			cache: true
		};
	}
	$select.select2(select2Options);
	var navigate = function(){
		var targetId = $.trim($select.val());
		if(targetId === ''){
			if(typeof layer !== 'undefined'){
				layer.msg('请先选择域名');
			}else{
				alert('请先选择域名');
			}
			return;
		}
		window.location.href = settings.buildUrl(targetId);
	};
	if(settings.buttonSelector){
		$(settings.buttonSelector).off('click.domainSwitch').on('click.domainSwitch', navigate);
	}
	return {
		navigate: navigate
	};
}

function quickSwitchDomain(basePath){
	if(typeof $ === 'undefined'){
		return false;
	}
	var targetId = $.trim($('#quickDomainSwitch').val());
	if(targetId === ''){
		if(typeof layer !== 'undefined'){
			layer.msg('请先选择域名');
		}else{
			alert('请先选择域名');
		}
		return false;
	}
	window.location.href = String(basePath || '') + targetId;
	return false;
}

if (typeof $.fn.bootstrapTable !== "undefined") {
    $.fn.bootstrapTable.custom = {
        method: 'post',
        contentType: "application/x-www-form-urlencoded",
        sortable: true,
        pagination: true,
        sidePagination: 'server',
        pageNumber: 1,
        pageSize: 20,
        pageList: [10, 15, 20, 30, 50, 100],
		loadingFontSize: '18px',
		toolbar: '#searchToolbar',
		showColumns: true,
		minimumCountColumns: 2,
		showToggle: true,
		showFullscreen: true,
		paginationPreText: '前页',
		paginationNextText: '后页',
		showJumpTo: true,
		paginationLoop: false,
		queryParamsType: '',
		queryParams: function(params) {
			$('#searchToolbar').find(':input[name]').each(function() {
				//if(!$(this).is(":visible")) return;
				params[$(this).attr('name')] = $(this).val()
			})
			updateQueryStr(params);
			params.offset = params.pageSize * (params.pageNumber-1);
			params.limit = params.pageSize;
			return params;
		},
        formatLoadingMessage: function(){
			return '';
		},
		formatShowingRows: function(t,n,r,e){
			return '显示第 '+t+' 到第 '+n+' 条, 总共 <b>'+r+'</b> 条';
		},
		formatRecordsPerPage: function(t){
			return '每页显示 '+t+' 条';
		},
		formatNoMatches: function(){
			return '没有找到匹配的记录';
		}
    };
    $.extend($.fn.bootstrapTable.defaults, $.fn.bootstrapTable.custom);
}

function httpGet(url, callback){
	$.ajax({
		url: url,
		type: 'get',
		dataType: 'json',
		success: function (res) {
			callback(res)
		},
		error: function () {
			if (typeof layer !== "undefined") {
				layer.closeAll();
				layer.msg('服务器错误');
			}
		}
	});
}

function httpPost(url, data, callback){
	$.ajax({
		url: url,
		type: 'post',
		data: data,
		dataType: 'json',
		success: function (res) {
			callback(res)
		},
		error: function () {
			if (typeof layer !== "undefined") {
				layer.closeAll();
				layer.msg('服务器错误');
			}
		}
	});
}

var isMobile = function(){
	if( /Android|SymbianOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Windows Phone|Midp/i.test(navigator.userAgent)) {
		return true;
	}
	return false;
}

function setCookie(name,value,expire = null)
{
	var cookie = name + "=" + escape(value);
	if(expire){
		var exp = new Date();
		exp.setTime(exp.getTime() + expire*1000);
		cookie += ";expires=" + exp.toGMTString();
	}
	document.cookie = cookie;
}
function getCookie(name)
{
	var arr,reg=new RegExp("(^| )"+name+"=([^;]*)(;|$)");
	if(arr=document.cookie.match(reg))
		return unescape(arr[2]);
	else
		return null;
}
function delCookie(name)
{
    var exp = new Date();
    exp.setTime(exp.getTime() - 1);
    var cval=getCookie(name);
    if(cval!=null){
      document.cookie= name + "="+cval+";expires="+exp.toGMTString();
    }
}
