<?php

/**
 * Smarty plugin
 *
 * @package	Smarty
 * @subpackage PluginsFunction
 */

function smarty_function_select($params, $template)
{
	$selected = $params["selected"];
	$options = json_decode($params["options"]); // json {href, caption}

	// create select for the web
	$di = \Phalcon\DI\FactoryDefault::getDefault();
	if($di->get('environment') == "web")
	{
		// create select for the web
		$select = '<select class="dropdown" onchange="apretaste.onSelect(this.value);">';
		foreach($options as $key=>$option) {
			$optionSelected = (strtoupper($option->caption) == strtoupper($selected)) ? "selected='selected'" : "";
			$select .= "<option value='{$option->href}' $optionSelected>{$option->caption}</option>";
		}
		$select .= '</select>';
	}
	// create select for the email and app
	else
	{
		// create select for the email and app
		$select = '<small>';
		foreach($options as $key=>$option) {
			// get the selected option
			if(strtoupper($option->caption) == strtoupper($selected)) $select .= "<b>{$option->caption}</b>";

			// get a selectable option
			else $select .= smarty_function_link(["href"=>$option->href, "caption"=>$option->caption, "wait"=>"false"], $template);

			// add the separator between each value (unless is the last one)
			if (end(array_keys($options)) != $key) $select .= " ".smarty_function_separator([], $template)." ";
		}
		$select .= '</small>';
	}

	// return the HTML code
	return $select;
}
