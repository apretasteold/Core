<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<style type="text/css">
			@media only screen and (max-width: 600px) {
				#container {
					width: 100%;
				}
			}
			@media only screen and (max-width: 480px) {
				.button {
					display: block !important;
				}
				.button a {
					display: block !important;
					font-size: 18px !important; width: 100% !important;
					max-width: 600px !important;
				}
				.section {
					width: 100%;
					margin: 2px 0px;
					display: block;
				}
				.phone-block {
					display: block;
				}
			}
			h1{
				color: #5EBB47;
				text-decoration: underline;
				font-size: 24px;
				margin-top: 0px;
			}
			h2{
				color: #5EBB47;
				font-size: 16px;
				margin-top: 0px;
			}
		</style>
	</head>
	<body leftmargin="0" marginwidth="0" topmargin="0" marginheight="0" offset="0" style="font-family: Arial;">
		<center>
			<table id="container" border="0" cellpadding="0" cellspacing="0" valign="top" align="center" width="600">
				{* top links *}
{*
				<tr>
					<td align="right" bgcolor="#D0D0D0" style="padding: 5px;">
						<small>
							{link href="AYUDA" caption="Ayuda"}{separator}
							{link href="INVITAR escriba aqui las direcciones email de sus amigos" caption="Invitar" body=""}{separator}
							{link href="PERFIL" caption="Profile"}{separator}
							{link href="SERVICIOS" caption="Servicios"}{separator}
							{link href="NOTIFICACIONES" caption="Alertas"}
							{if $num_notifications > 0}
								<!--[if mso]>
								<v:roundrect xmlns:v='urn:schemas-microsoft-com:vml' xmlns:w='urn:schemas-microsoft-com:office:word' style='v-text-anchor:middle;' arcsize='5%' strokecolor='$stroke' fillcolor='#FF0000'>
								<w:anchorlock/>
								<center style='color:#FFFFFF;font-family:Helvetica, Arial,sans-serif;font-size:9px;'><strong>{$num_notifications}</strong></center>
								</v:roundrect>
								<![endif]-->
								<a style='background-color:#FF0000;border-radius:3px;color:#FFFFFF;display:inline-block;font-family:sans-serif;font-size:9px;text-align:center;text-decoration:none;line-height:20px;padding-left:2px;padding-right:2px;-webkit-text-size-adjust:none;mso-hide:all;'><b>{$num_notifications}</b></a>
							{/if}
						</small>
					</td>
				</tr>
*}

				{* logo and service name *}
				<tr>
					<td bgcolor="#F2F2F2" align="center" valign="middle">
						<table border="0">
							<tr>
								<td class="phone-block" style="margin-right: 20px;" valign="middle">
									<span style="font-size:45px; white-space:nowrap; font-family:Tahoma; color:#5ebb47;">
										<i>A</i><span style="color:#A03E3B;"><i>!</i></span>
									</span>
								</td>
							</tr>
						</table>
					</td>
				</tr>

				{* top ad *}
{*
				{if $APRETASTE_ADS|@count gt 0}
				<tr><td><table width="100%" cellpadding="0" cellspacing="0"><tr>
					<td bgcolor="#c3daee" valign="middle" width="1"><font color="#337AB7"><big><b>&#9733;</b>&nbsp;</big></font></td>
					<td bgcolor="#c3daee"><small>{$APRETASTE_ADS[0]->title}</small></td>
					<td bgcolor="#c3daee" align="right" valign="middle">
						{button href="PUBLICIDAD {$APRETASTE_ADS[0]->id}" caption="Ver m&aacute;s" size="small" color="blue"}
					</td>
				</tr></table></td></tr>
				{/if}
*}

				{* the stars game *}
{*
				{if $requests_today == 0}
					{if $raffle_stars > 0}
					<tr>
						<td align="center">
							<big>
								<br/>
								{if $raffle_stars < 5}
									Tu primer correo del d&iacute;a<br/>
								{else}
									<b>&iexcl;FELICIDADES!</b><br/>
								{/if}
								{for $i=1 to 5}
									{if $i <= $raffle_stars}
										<font color="black" size="5"><b>&#9733;</b></font>
									{else}
										<font color="black" size="5">&#9734;</font>
									{/if}
								{/for}
								<br/>
							</big>
							<small>
								{if $raffle_stars < 5}
									Por 5 d&iacute;as consecutivos ganar&aacute;s &sect;1 de cr&eacute;dito personal<br/>
									{if $raffle_stars > 0}<i>Ya vas por {$raffle_stars}. &iexcl;Emb&uacute;llate!</i>{/if}
								{else}
									Haz ganado <b>&sect;1</b> de cr&eacute;dito personal<br/>
									Entra ma&ntilde;ana y gana incluso m&aacute;s cr&eacute;dito
								{/if}
							</small>
						</td>
					</tr>
					{/if}
				{/if}
*}

				{* main section to load the user template *}
				<tr>
					<td align="left" style="padding: 0px 5px;">
						{space10}
						{include file="$APRETASTE_USER_TEMPLATE"}
						{space10}
					</td>
				</tr>

				{* subscribe to email list *}
{*
				{if ! $APRETASTE_EMAIL_LIST}
				<tr>
					<td align="center" bgcolor="red">
						<tr><td><table width="100%" cellpadding="0" cellspacing="0"><tr>
							<td bgcolor="#F0AD4E" valign="middle" width="1"><font color="red"><big>&nbsp;&#9888;&nbsp;</big></font></td>
							<td bgcolor="#F0AD4E"><small>Usted no esta suscrito a nuestra lista de correos, por lo cual no recibir&aacute; informaci&oacute;n sobre nuevos servicios, concursos y rifas.</small></td>
							<td bgcolor="#F0AD4E" align="right" valign="middle">
								{button href="SUSCRIPCION LISTA ENTRAR" caption="Suscribirse" size="small" color="green"}
							</td>
						</tr></table></td></tr>
					</td>
				</tr>
				{/if}
*}

				{* bottom ad *}
{*
				{if $APRETASTE_ADS|@count gt 1}
				<tr><td><table width="100%" cellpadding="0" cellspacing="0"><tr>
					<td bgcolor="#c3daee" valign="middle" width="1"><font color="#337AB7"><big><b>&#9733;</b>&nbsp;</big></font></td>
					<td bgcolor="#c3daee"><small>{$APRETASTE_ADS[1]->title}</small></td>
					<td bgcolor="#c3daee" align="right" valign="middle">
						{button href="PUBLICIDAD {$APRETASTE_ADS[1]->id}" caption="Ver m&aacute;s" size="small" color="blue"}
					</td>
				</tr></table></td></tr>
				{/if}
*}

				{* services related *}
{*
				{if $APRETASTE_SERVICE_RELATED|@count gt 0}
				<tr bgcolor="#e6e6e6">
					<td align="left" style="padding: 0px 5px;">
						{space5}
						<small>
							Otros servicios:
							{foreach $APRETASTE_SERVICE_RELATED as $APRETASTE_SERVICE}
								{link href="{$APRETASTE_SERVICE}" caption="{$APRETASTE_SERVICE}"}
								{if not $APRETASTE_SERVICE@last}{separator}{/if}
							{/foreach}
						</small>
						{space5}
					</td>
				</tr>
				{/if}
*}

				{* footer *}
{*
				<tr>
					<td align="center" bgcolor="#F2F2F2">
						{space5}
						<small>
							&iquest;Tiene dudas? Escriba a <a href="mailto:{$APRETASTE_SUPPORT_EMAIL}">{$APRETASTE_SUPPORT_EMAIL}</a><br/>
							Mire los {link href="TERMINOS" caption="T&eacute;rminos de uso"}<br/>
						</small>
						{space5}
					</td>
				</tr>
*}
			</table>
		</center>
	</body>
</html>
