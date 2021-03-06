<?php
/* 
Plugin Name: WooCommerce - SMS Notifications for Solutions Infini API
Version: 1.0
Plugin URI: http://bigkahuna.in/woocommerce-apg-sms-notifications
Description: Keep your customer updated about the order status using SMS. Optionally, store owners receive an SMS whenever a new order is placed. NOTE: You need to subscribe to Solutions Infini (Transactional route).
Author URI: http://bigkahuna.in
Author: Chirag Vora

Text Domain: apg_sms
Domain Path: /lang
License: GPL2
*/

/*  Copyright 2013 

    This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

    You should have received a copy of the GNU General Public License along with this program; if not, write to the Free Software Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/*AKNOWLEDGEMENT: Emilio Jose from The Art Project Group to make the plugin visit: www.artprojectgroup.es */




//Print the configuration form
function apg_sms_tab() {
	wp_enqueue_style('apg_sms_hoja_de_estilo'); //Load the stylesheet
	include('formulario.php');
}

//Add menu to WooCommerce
function apg_sms_admin_menu() {
	add_submenu_page('woocommerce', __('APG SMS Notifications', 'apg_sms'),  __('SMS Notifications', 'apg_sms') , 'manage_woocommerce', 'apg_sms', 'apg_sms_tab');
}
add_action('admin_menu', 'apg_sms_admin_menu', 15);

//load scripts and CSS
function apg_sms_screen_id($woocommerce_screen_ids) {
	global $woocommerce;

	$woocommerce_screen_ids[] = 'woocommerce_page_apg_sms';

	return $woocommerce_screen_ids;
}
add_filter('woocommerce_screen_ids', 'apg_sms_screen_id');

//Add options
function apg_sms_registra_opciones() {
	register_setting('apg_sms_settings_group', 'apg_sms_settings');
}
add_action('admin_init', 'apg_sms_registra_opciones');

//Process the SMS
function apg_sms_procesa_estados($pedido) {
	global $woocommerce;

	$pedido = new WC_Order($pedido);
	$estado = $pedido->status;
	$nombres_de_estado = array('on-hold' => 'Recibido', 'processing' => __('Processing', 'apg_sms'), 'completed' => __('Completed', 'apg_sms'));
	foreach ($nombres_de_estado as $nombre_de_estado => $traduccion) if ($estado == $nombre_de_estado) $estado = $traduccion;

	$configuracion = get_option('apg_sms_settings'); //Record the settings
	
	$internacional = false;
	$telefono = apg_sms_procesa_el_telefono($pedido, $pedido->billing_phone, $configuracion['servicio']);
	if ($pedido->billing_country && ($woocommerce->countries->get_base_country() != $pedido->billing_country)) $internacional = true;
	
	if ($estado == 'Recibido')
	{
		if (isset($configuracion['notificacion']) && $configuracion['notificacion'] == 1) apg_sms_envia_sms($configuracion, $configuracion['telefono'], apg_sms_procesa_variables($configuracion['mensaje_pedido'], $pedido, $configuracion['variables'])); //Mensaje para el propietario
		$mensaje = apg_sms_procesa_variables($configuracion['mensaje_recibido'], $pedido, $configuracion['variables']);
	}
	else if ($estado == __('Processing', 'apg_sms')) $mensaje = apg_sms_procesa_variables($configuracion['mensaje_procesando'], $pedido, $configuracion['variables']);
	else if ($estado == __('Completed', 'apg_sms')) $mensaje = apg_sms_procesa_variables($configuracion['mensaje_completado'], $pedido, $configuracion['variables']);

	if (!$internacional || (isset($configuracion['internacional']) && $configuracion['internacional'] == 1)) apg_sms_envia_sms($configuracion, $telefono, $mensaje);
}
add_action('woocommerce_order_status_completed', 'apg_sms_procesa_estados', 10);//This works when the order is marked as complete
add_action('woocommerce_order_status_processing', 'apg_sms_procesa_estados', 10);//This works when the order is marked as processed

//Monitor the changes in order status
add_action('woocommerce_order_status_pending_to_processing_notification', 'apg_sms_procesa_estados', 10);
add_action('woocommerce_order_status_pending_to_on-hold_notification', 'apg_sms_procesa_estados', 10);
add_action('woocommerce_order_status_pending_to_completed_notification', 'apg_sms_procesa_estados', 10);

//Send notes via SMS client
function apg_sms_procesa_notas($datos) {
	global $woocommerce;
	
	$pedido = new WC_Order($datos['order_id']);
	
	$configuracion = get_option('apg_sms_settings');
	
	$internacional = false;
	$telefono = apg_sms_procesa_el_telefono($pedido, $pedido->billing_phone, $configuracion['servicio']);
	if ($pedido->billing_country && ($woocommerce->countries->get_base_country() != $pedido->billing_country)) $internacional = true;
	
	if (!$internacional || (isset($configuracion['internacional']) && $configuracion['internacional'] == 1)) apg_sms_envia_sms($configuracion, $telefono, apg_sms_procesa_variables($configuracion['mensaje_nota'], $pedido, $configuracion['variables'], wptexturize($datos['customer_note'])));
}
add_action('woocommerce_new_customer_note', 'apg_sms_procesa_notas', 10);

//Send the SMS message
function apg_sms_envia_sms($configuracion, $telefono, $mensaje) {
	if ($configuracion['servicio'] == "solutions_infini") $respuesta = apg_sms_curl("http://alerts.sinfini.com/api/web2sms.php?workingkey=" . $configuracion['clave_solutions_infini'] . "&to=" . $telefono . "&sender=" . $configuracion['identificador_solutions_infini'] . "&message=" . apg_sms_codifica_el_mensaje($mensaje));
}

//Read the pages external to this website
function apg_sms_curl($url) {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_TIMEOUT, 10);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
	$resultado = curl_exec ($ch);
	curl_close($ch);
		
	return utf8_encode($resultado); 
}

//We normalize the text
function apg_sms_normaliza_mensaje($mensaje)
{
	$reemplazo = array('Š'=>'S', 'š'=>'s', 'Đ'=>'Dj', 'đ'=>'dj', 'Ž'=>'Z', 'ž'=>'z', 'Č'=>'C', 'č'=>'c', 'Ć'=>'C', 'ć'=>'c', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E', 'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U', 'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U',
	'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c', 'è'=>'e', 'é'=>'e', 'ê'=>'e',  'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y',  'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y', 'Ŕ'=>'R', 'ŕ'=>'r', "`" => "'", "´" => "'", "„" => ",",
	"`" => "'", "´" => "'", "“" => "\"", "”" => "\"", "´" => "'", "&acirc;€™" => "'", "{" => "", "~" => "", "–" => "-", "’" => "'", "!" => ".", "¡" => "", "?" => ".", "¿" => "");
 
	$mensaje = str_replace(array_keys($reemplazo), array_values($reemplazo), htmlentities($mensaje, ENT_QUOTES, "UTF-8"));
 
	return $mensaje;
}

//Encodes the message
function apg_sms_codifica_el_mensaje($mensaje) {
	return urlencode(htmlentities($mensaje, ENT_QUOTES, "UTF-8"));
}

/*
//See if you need international phone code
function apg_sms_prefijo($servicio) {
	if ($servicio == "clockwork" || $servicio == "clickatell" || $servicio == "bulksms" || $servicio == "msg91" || $servicio == "twillio") return true;
	
	return false;
}

//Processes the phone number and add the prefix if required
function apg_sms_procesa_el_telefono($pedido, $telefono, $servicio) {
	global $woocommerce;
	
	$prefijo = apg_sms_prefijo($servicio);
	
	$telefono = str_replace(array('+','-'), '', filter_var($telefono, FILTER_SANITIZE_NUMBER_INT));
	if ($pedido->billing_country && ($woocommerce->countries->get_base_country() != $pedido->billing_country || $prefijo))
	{
		$prefijo_internacional = dame_prefijo_pais($pedido->billing_country);
		preg_match("/(\d{1,4})[0-9.\- ]+/", $telefono, $prefijo);
		if (strpos($prefijo[1], $prefijo_internacional) === false) $telefono = $prefijo_internacional . $telefono;
		if ($servicio == "twillio") $telefono = "+" . $telefono;
	}

	return $telefono;
}
*/

//Process variables
function apg_sms_procesa_variables($mensaje, $pedido, $variables, $nota = '') {
	$apg_sms = array("id", "order_key", "billing_first_name", "billing_last_name", "billing_company", "billing_address_1", "billing_address_2", "billing_city", "billing_postcode", "billing_country", "billing_state", "billing_email", "billing_phone", "shipping_first_name", "shipping_last_name", "shipping_company", "shipping_address_1", "shipping_address_2", "shipping_city", "shipping_postcode", "shipping_country", "shipping_state", "shipping_method", "shipping_method_title", "payment_method", "payment_method_title", "order_subtotal", "order_discount", "cart_discount", "order_tax", "order_shipping", "order_shipping_tax", "order_total", "status", "shop_name", "note"); 

	$variables = str_replace(array("\r\n", "\r"), "\n", $variables);
	$variables = explode("\n", $variables);

	preg_match_all("/%(.*?)%/", $mensaje, $busqueda);

	foreach ($busqueda[1] as $variable) 
	{ 
    	$variable = strtolower($variable);

    	if (!in_array($variable, $apg_sms) && !in_array($variable, $variables)) continue;

    	if ($variable != "shop_name" && $variable != "note") 
		{
			if (in_array($variable, $apg_sms)) $mensaje = str_replace("%" . $variable . "%", $pedido->$variable, $mensaje); //Variables estándar
			else $mensaje = str_replace("%" . $variable . "%", $pedido->order_custom_fields[$variable][0], $mensaje); //Variables personalizadas
		}
		else if ($variable == "shop_name") $mensaje = str_replace("%" . $variable . "%", get_bloginfo('name'), $mensaje);
		else if ($variable == "note") $mensaje = str_replace("%" . $variable . "%", $nota, $mensaje);
	}
	
	return $mensaje;
}

/*
//Returns the country code prefix
function dame_prefijo_pais($pais = '') {
	$paises = array('AC' => '247', 'AD' => '376', 'AE' => '971', 'AF' => '93', 'AG' => '1268', 'AI' => '1264', 'AL' => '355', 'AM' => '374', 'AO' => '244', 'AQ' => '672', 'AR' => '54', 'AS' => '1684', 'AT' => '43', 'AU' => '61', 'AW' => '297', 'AX' => '358', 'AZ' => '994', 'BA' => '387', 'BB' => '1246', 'BD' => '880', 'BE' => '32', 'BF' => '226', 'BG' => '359', 'BH' => '973', 'BI' => '257', 'BJ' => '229', 'BL' => '590', 'BM' => '1441', 'BN' => '673', 'BO' => '591', 'BQ' => '599', 'BR' => '55', 'BS' => '1242', 'BT' => '975', 'BW' => '267', 
	'BY' => '375', 'BZ' => '501', 'CA' => '1', 'CC' => '61', 'CD' => '243', 'CF' => '236', 'CG' => '242', 'CH' => '41', 'CI' => '225', 'CK' => '682', 'CL' => '56', 'CM' => '237', 'CN' => '86', 'CO' => '57', 'CR' => '506', 'CU' => '53', 'CV' => '238', 'CW' => '599', 'CX' => '61', 'CY' => '357', 'CZ' => '420', 'DE' => '49', 'DJ' => '253', 'DK' => '45', 'DM' => '1767', 'DO' => '1809', 'DO' => '1829', 'DO' => '1849', 'DZ' => '213', 
	'EC' => '593', 'EE' => '372', 'EG' => '20', 'EH' => '212', 'ER' => '291', 'ES' => '34', 'ET' => '251', 'EU' => '388', 'FI' => '358', 'FJ' => '679', 'FK' => '500', 'FM' => '691', 'FO' => '298', 'FR' => '33', 'GA' => '241', 'GB' => '44', 'GD' => '1473', 'GE' => '995', 'GF' => '594', 'GG' => '44', 'GH' => '233', 'GI' => '350', 'GL' => '299', 'GM' => '220', 'GN' => '224', 'GP' => '590', 'GQ' => '240', 'GR' => '30', 'GT' => '502', 'GU' => '1671', 'GW' => '245', 'GY' => '592', 'HK' => '852', 'HN' => '504', 'HR' => '385', 'HT' => '509',
	'HU' => '36', 'ID' => '62', 'IE' => '353', 'IL' => '972', 'IM' => '44', 'IN' => '91', 'IO' => '246', 'IQ' => '964', 'IR' => '98', 'IS' => '354', 'IT' => '39', 'JE' => '44', 'JM' => '1876', 'JO' => '962', 'JP' => '81', 'KE' => '254', 'KG' => '996', 'KH' => '855', 'KI' => '686', 'KM' => '269', 'KN' => '1869', 'KP' => '850', 'KR' => '82', 'KW' => '965', 'KY' => '1345', 'KZ' => '7', 'LA' => '856', 'LB' => '961', 'LC' => '1758', 'LI' => '423', 'LK' => '94', 'LR' => '231', 'LS' => '266', 'LT' => '370', 'LU' => '352', 'LV' => '371', 
	'LY' => '218', 'MA' => '212', 'MC' => '377', 'MD' => '373', 'ME' => '382', 'MF' => '590', 'MG' => '261', 'MH' => '692', 'MK' => '389', 'ML' => '223', 'MM' => '95', 'MN' => '976', 'MO' => '853', 'MP' => '1670', 'MQ' => '596', 'MR' => '222', 'MS' => '1664', 'MT' => '356', 'MU' => '230', 'MV' => '960', 'MW' => '265', 'MX' => '52', 'MY' => '60', 'MZ' => '258', 'NA' => '264', 'NC' => '687', 'NE' => '227', 'NF' => '672', 'NG' => '234',
	'NI' => '505', 'NL' => '31', 'NO' => '47', 'NP' => '977', 'NR' => '674', 'NU' => '683', 'NZ' => '64', 'OM' => '968', 'PA' => '507', 'PE' => '51', 'PF' => '689', 'PG' => '675', 'PH' => '63', 'PK' => '92', 'PL' => '48', 'PM' => '508', 'PR' => '1787', 'PR' => '1939', 'PS' => '970', 'PT' => '351', 'PW' => '680', 'PY' => '595', 'QA' => '974', 'QN' => '374', 'QS' => '252', 'QY' => '90', 'RE' => '262', 'RO' => '40', 'RS' => '381', 'RU' => '7', 'RW' => '250', 'SA' => '966', 'SB' => '677', 'SC' => '248', 'SD' => '249', 'SE' => '46', 
	'SG' => '65', 'SH' => '290', 'SI' => '386', 'SJ' => '47', 'SK' => '421', 'SL' => '232', 'SM' => '378', 'SN' => '221', 'SO' => '252', 'SR' => '597', 'SS' => '211', 'ST' => '239', 'SV' => '503', 'SX' => '1721', 'SY' => '963', 'SZ' => '268', 'TA' => '290', 'TC' => '1649', 'TD' => '235', 'TG' => '228', 'TH' => '66', 'TJ' => '992', 'TK' => '690', 'TL' => '670', 'TM' => '993', 'TN' => '216', 'TO' => '676', 'TR' => '90', 'TT' => '1868',
	'TV' => '688', 'TW' => '886', 'TZ' => '255', 'UA' => '380', 'UG' => '256', 'UK' => '44', 'US' => '1', 'UY' => '598', 'UZ' => '998', 'VA' => '379', 'VA' => '39', 'VC' => '1784', 'VE' => '58', 'VG' => '1284', 'VI' => '1340', 'VN' => '84', 'VU' => '678', 'WF' => '681', 'WS' => '685', 'XC' => '991', 'XD' => '888', 'XG' => '881', 'XL' => '883', 'XN' => '857', 'XN' => '858', 'XN' => '870', 'XP' => '878', 'XR' => '979', 'XS' => '808', 'XT' => '800', 'XV' => '882', 'YE' => '967', 'YT' => '262', 'ZA' => '27', 'ZM' => '260', 'ZW' => '263');

	return ($pais == '') ? $paises : (isset($paises[$pais]) ? $paises[$pais] : '');
}
*/
//Get all the information about the plugin
function apg_sms_plugin($nombre) {
	$argumentos = (object) array('slug' => $nombre);
	$consulta = array('action' => 'plugin_information', 'timeout' => 15, 'request' => serialize($argumentos));
	$url = 'http://api.wordpress.org/plugins/info/1.0/';
	$respuesta = wp_remote_post($url, array('body' => $consulta));
	$plugin = unserialize($respuesta['body']);
	
	return get_object_vars($plugin);
}

//Displays the update message... please update the settings.. it's important!
function apg_sms_actualizacion() {
	global $apg_sms;
	
    echo '<div class="error fade" id="message"><h3>' . $apg_sms['plugin'] . '</h3><h4>' . sprintf(__("Please, update your %s. It's very important!", 'apg_sms'), '<a href="' . $apg_sms['ajustes'] . '" title="' . __('Settings', 'apg_sms') . '">' . __('settings', 'apg_sms') . '</a>') . '</h4></div>';
}

//Loads stylesheet
function apg_sms_muestra_mensaje() {
	wp_register_style('apg_sms_hoja_de_estilo', plugins_url('style.css', __FILE__)); //Loads the stylesheet
	wp_register_style('apg_sms_fuentes', plugins_url('fonts/stylesheet.css', __FILE__)); //Loads the global stylesheet
	wp_enqueue_style('apg_sms_fuentes'); //Loads the global stylesheet
	
	$configuracion = get_option('apg_sms_settings');
//	if (!isset($configuracion['mensaje_pedido']) || !isset($configuracion['mensaje_nota'])) add_action('admin_notices', 'apg_sms_actualizacion'); // Check whether to show the update settings message
}
add_action('admin_init', 'apg_sms_muestra_mensaje');

//Remove all traces of the plugin during uninstall
function apg_sms_desinstalar() {
  delete_option('apg_sms_settings');
}
register_deactivation_hook( __FILE__, 'apg_sms_desinstalar' );
?>
