<?php
/**
 * @package     Joomla.Site
 * @subpackage  Layout
 *
 * @copyright   (C) 2016 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

extract($displayData);

/**
 * Layout variables
 * -----------------
 * @var   string   $autocomplete    Autocomplete attribute for the field.
 * @var   boolean  $autofocus       Is autofocus enabled?
 * @var   string   $class           Classes for the input.
 * @var   string   $description     Description of the field.
 * @var   boolean  $disabled        Is this field disabled?
 * @var   string   $group           Group the field belongs to. <fields> section in form XML.
 * @var   boolean  $hidden          Is this field hidden in the form?
 * @var   string   $hint            Placeholder for the field.
 * @var   string   $id              DOM id of the field.
 * @var   string   $label           Label of the field.
 * @var   string   $labelclass      Classes to apply to the label.
 * @var   boolean  $multiple        Does this field support multiple values?
 * @var   string   $name            Name of the input field.
 * @var   string   $onchange        Onchange attribute for the field.
 * @var   string   $onclick         Onclick attribute for the field.
 * @var   string   $pattern         Pattern (Reg Ex) of value of the form field.
 * @var   boolean  $readonly        Is this field read only?
 * @var   boolean  $repeat          Allows extensions to duplicate elements.
 * @var   boolean  $required        Is this field required?
 * @var   boolean  $rules           Are the rules to be displayed?
 * @var   integer  $size            Size attribute of the input.
 * @var   boolean  $spellcheck      Spellcheck state for the form field.
 * @var   string   $validate        Validation rules to apply.
 * @var   string   $value           Value attribute of the field.
 * @var   array    $checkedOptions  Options that will be set as checked.
 * @var   boolean  $hasValue        Has this field a value assigned?
 * @var   array    $options         Options available for this field.
 * @var   array    $inputType       Options available for this field.
 * @var   string   $accept          File types that are accepted.
<<<<<<< HEAD
 * @var   boolean  $lock            Is this field locked.
=======
 * @var   string   $dataAttribute   Miscellaneous data attributes preprocessed for HTML output
 * @var   array    $dataAttributes  Miscellaneous data attribute for eg, data-*.
>>>>>>> 5ddc984651db52d74ccaac71ab714ffbe101234c
 */

/** @var Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = Factory::getApplication()->getDocument()->getWebAssetManager();

if ($meter)
{
	$wa->useScript('field.passwordstrength');

	$class = 'js-password-strength ' . $class;

	if ($forcePassword)
	{
		$class = $class . ' meteredPassword';
	}
}

$wa->useScript('field.passwordview');

Text::script('JFIELD_PASSWORD_INDICATE_INCOMPLETE');
Text::script('JFIELD_PASSWORD_INDICATE_COMPLETE');
Text::script('JSHOWPASSWORD');
Text::script('JHIDEPASSWORD');

if ($lock)
{
	// Load script on document load.
	JFactory::getDocument()->addScriptDeclaration(
			"
		jQuery(document).ready(function() {
			jQuery('#" . $id ."_lock').on('click', function() {
				var lockButton = jQuery(this);
				var passwordInput = jQuery('#" . $id . "');
				var lock = lockButton.hasClass('active');

				if (lock === true) {
					lockButton.html('" . JText::_('JMODIFY', true) . "');
					passwordInput.attr('disabled', true);
					passwordInput.val('');
				}
				else
				{
					lockButton.html('" . JText::_('JCANCEL', true) . "');
					passwordInput.attr('disabled', false);
				}
			});
		});"
	);

	$disabled = true;
	$hint = str_repeat('*', strlen($value));
	$value = '';
}

$attributes = array(
	strlen($hint) ? 'placeholder="' . htmlspecialchars($hint, ENT_COMPAT, 'UTF-8') . '"' : '',
	!empty($autocomplete) ? 'autocomplete="' . $autocomplete . '"' : '',
	!empty($class) ? 'class="form-control ' . $class . '"' : 'class="form-control"',
	!empty($description) ? 'aria-describedby="' . $name . '-desc"' : '',
	$readonly ? 'readonly' : '',
	$disabled ? 'disabled' : '',
	!empty($size) ? 'size="' . $size . '"' : '',
	!empty($maxLength) ? 'maxlength="' . $maxLength . '"' : '',
	$required ? 'required' : '',
	$autofocus ? 'autofocus' : '',
	!empty($minLength) ? 'data-min-length="' . $minLength . '"' : '',
	!empty($minIntegers) ? 'data-min-integers="' . $minIntegers . '"' : '',
	!empty($minSymbols) ? 'data-min-symbols="' . $minSymbols . '"' : '',
	!empty($minUppercase) ? 'data-min-uppercase="' . $minUppercase . '"' : '',
	!empty($minLowercase) ? 'data-min-lowercase="' . $minLowercase . '"' : '',
	!empty($forcePassword) ? 'data-min-force="' . $forcePassword . '"' : '',
	$dataAttribute,
);

if ($rules && !empty($description))
{
	$requirements = [];

	if ($minLength)
	{
		$requirements[] = Text::sprintf('JFIELD_PASSWORD_RULES_CHARACTERS', $minLength);
	}

	if ($minIntegers)
	{
		$requirements[] = Text::sprintf('JFIELD_PASSWORD_RULES_DIGITS', $minIntegers);
	}

	if ($minSymbols)
	{
		$requirements[] = Text::sprintf('JFIELD_PASSWORD_RULES_SYMBOLS', $minSymbols);
	}

	if ($minUppercase)
	{
		$requirements[] = Text::sprintf('JFIELD_PASSWORD_RULES_UPPERCASE', $minUppercase);
	}

	if ($minLowercase)
	{
		$requirements[] = Text::sprintf('JFIELD_PASSWORD_RULES_LOWERCASE', $minLowercase);
	}
}
?>
<<<<<<< HEAD
<?php if ($lock): ?>
    <span class="input-append">
<?php endif; ?>
<input
    type="password"
    name="<?php echo $name; ?>"
    id="<?php echo $id; ?>"
    value="<?php echo htmlspecialchars($value, ENT_COMPAT, 'UTF-8'); ?>"
    <?php echo implode(' ', $attributes); ?>
/>
<?php if ($lock): ?>
    <button type="button" id="<?php echo $id; ?>_lock" class="btn btn-info" data-toggle="button"><?php echo JText::_('JMODIFY'); ?></button>
    </span>
<?php endif; ?>
=======
<?php if (!empty($description)) : ?>
	<div id="<?php echo $name . '-desc'; ?>" class="small text-muted">
		<?php if ($rules) : ?>
			<?php echo Text::sprintf($description, implode(', ', $requirements)); ?>
		<?php else : ?>
			<?php echo Text::_($description); ?>
		<?php endif; ?>
	</div>
<?php endif; ?>

<div class="password-group">
	<div class="input-group">
		<input
			type="password"
			name="<?php echo $name; ?>"
			id="<?php echo $id; ?>"
			value="<?php echo htmlspecialchars($value, ENT_COMPAT, 'UTF-8'); ?>"
			<?php echo implode(' ', $attributes); ?>>
		<span class="input-group-append">
			<button type="button" class="btn btn-secondary input-password-toggle">
				<span class="icon-eye icon-fw" aria-hidden="true"></span>
				<span class="sr-only"><?php echo Text::_('JSHOWPASSWORD'); ?></span>
			</button>
		</span>
	</div>
</div>
>>>>>>> 5ddc984651db52d74ccaac71ab714ffbe101234c
