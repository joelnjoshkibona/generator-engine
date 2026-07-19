<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Validation;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;

class ValidationGenerator extends BaseGenerator
{
	protected array $fields;
	protected array $validations;
	protected string $operation; // 'create' or 'edit'

	public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
	{
		parent::__construct($moduleName, $moduleGroup, $config);
		$this->fields = $config['fields'] ?? [];
		$this->validations = $config['validations'] ?? [];
		$this->operation = $config['operation'] ?? 'create';
	}

	public function generate(): bool
	{
		// ValidationGenerator doesn't generate files, it just processes validation rules
		return true;
	}

	public function generateValidationRules(): array
	{
		$rules = [];
		
		// If validations array is provided, use it directly
		if (!empty($this->validations)) {
			foreach ($this->validations as $fieldName => $validation) {
				$fieldRules = $this->processValidationRules($validation['rules'] ?? []);
				if (!empty($fieldRules)) {
					$rules[$fieldName] = $fieldRules;
				}
			}
		} else {
			// Fallback to old field-based approach
			foreach ($this->fields as $field) {
				$fieldRules = $this->generateFieldRules($field);
				if (!empty($fieldRules)) {
					$rules[$field['name']] = $fieldRules;
				}
			}
		}
		
		return $rules;
	}

	public function generateValidationMessages(): array
	{
		$messages = [];
		
		// If validations array is provided, use it directly
		if (!empty($this->validations)) {
			foreach ($this->validations as $fieldName => $validation) {
				$fieldMessages = $this->processValidationMessages($fieldName, $validation['messages'] ?? []);
				$messages = array_merge($messages, $fieldMessages);
			}
		} else {
			// Fallback to old field-based approach
			foreach ($this->fields as $field) {
				$fieldMessages = $this->generateFieldMessages($field);
				$messages = array_merge($messages, $fieldMessages);
			}
		}
		
		return $messages;
	}

	protected function processValidationRules(array $rules): array
	{
		$processedRules = [];
		
		// Handle both numeric keyed arrays and associative arrays
		foreach ($rules as $key => $rule) {
			$rule = trim($rule);
			if (empty($rule)) continue;
			
			// Handle unique rules for edit operations
			if (str_starts_with($rule, 'unique:')) {
				$processedRules[] = self::processUniqueRule($rule, '', $this->operation === 'edit');
			} else {
				$processedRules[] = $rule;
			}
		}
		
		return $processedRules;
	}

	protected function processValidationMessages(string $fieldName, array $messages): array
	{
		$processedMessages = [];
		
		foreach ($messages as $ruleName => $message) {
			$messageKey = "{$fieldName}.{$ruleName}";
			$processedMessages[$messageKey] = $message;
		}
		
		return $processedMessages;
	}

	protected function generateFieldRules(array $field): array
	{
		$rules = [];
		$fieldName = $field['name'];
		$rulesString = $field['rules'] ?? '';
		
		if (empty($rulesString)) {
			return $rules;
		}
		
		// Parse the rules string
		$ruleArray = explode('|', $rulesString);
		
		foreach ($ruleArray as $rule) {
			$rule = trim($rule);
			if (empty($rule)) continue;
			
			// Handle special cases
			if ($rule === 'required') {
				$rules[] = 'required';
			} elseif ($rule === 'nullable') {
				$rules[] = 'nullable';
			} elseif ($rule === 'string') {
				$rules[] = 'string';
			} elseif ($rule === 'integer') {
				$rules[] = 'integer';
			} elseif ($rule === 'email') {
				$rules[] = 'email';
			} elseif ($rule === 'numeric') {
				$rules[] = 'numeric';
			} elseif ($rule === 'boolean') {
				$rules[] = 'boolean';
			} elseif ($rule === 'date') {
				$rules[] = 'date';
			} elseif ($rule === 'datetime') {
				$rules[] = 'date';
			} elseif (str_starts_with($rule, 'date_format:')) {
				$rules[] = $rule;
			} elseif (str_starts_with($rule, 'max:')) {
				$rules[] = $rule;
			} elseif (str_starts_with($rule, 'min:')) {
				$rules[] = $rule;
			} elseif (str_starts_with($rule, 'unique:')) {
				$rules[] = self::processUniqueRule($rule, $fieldName, $this->operation === 'edit');
			} elseif (str_starts_with($rule, 'exists:')) {
				$rules[] = $rule;
			} elseif (str_starts_with($rule, 'in:')) {
				$rules[] = $rule;
			} else {
				$rules[] = $rule;
			}
		}
		
		return $rules;
	}

	/**
	 * Rebuild a "unique:table,column[,...]" rule for the given operation.
	 *
	 * Table and column are parsed out of the rule; whatever (if anything)
	 * follows the column — including a literal "__ID__" placeholder some
	 * module configs use as a human-readable stand-in — is discarded and
	 * replaced deterministically:
	 *  - Edit ($isEdit = true): "unique:{table},{column},{$model->id}" — the
	 *    "{$model->id}" is emitted as literal, unescaped PHP interpolation
	 *    syntax inside a double-quoted string in the *generated* file, so it
	 *    is evaluated at runtime by the generated service, excluding the
	 *    record being edited from the uniqueness check.
	 *  - Create ($isEdit = false): "unique:{table},{column}" — no record to
	 *    exclude yet, so no id/placeholder is appended at all.
	 *
	 * Public + static: called from BaseServiceGenerator::generateValidationRules()
	 * (a sibling generator, not a subclass) as well as internally.
	 */
	public static function processUniqueRule(string $rule, string $fieldName, bool $isEdit): string
	{
		// Parse unique:table,column
		$parts = explode(':', $rule);
		if (count($parts) < 2) {
			return $rule;
		}

		$tableColumn = $parts[1];
		$tableColumnParts = explode(',', $tableColumn);
		$table = $tableColumnParts[0];
		$column = $tableColumnParts[1] ?? $fieldName;

		if ($isEdit) {
			// For edit operations, we need to exclude the current record
			return "unique:{$table},{$column},{\$model->id}";
		} else {
			// For create operations, just check uniqueness
			return "unique:{$table},{$column}";
		}
	}

	protected function generateFieldMessages(array $field): array
	{
		$messages = [];
		$fieldName = $field['name'];
		$rulesString = $field['rules'] ?? '';
		
		if (empty($rulesString)) {
			return $messages;
		}
		
		// Parse the rules string
		$ruleArray = explode('|', $rulesString);
		
		foreach ($ruleArray as $rule) {
			$rule = trim($rule);
			if (empty($rule)) continue;
			
			$ruleName = explode(':', $rule)[0];
			$messageKey = "{$fieldName}.{$ruleName}";
			
			// Generate appropriate message based on rule type
			switch ($ruleName) {
				case 'required':
					$messages[$messageKey] = "The " . ucfirst($fieldName) . " field is required.";
					break;
				case 'string':
					$messages[$messageKey] = "The " . ucfirst($fieldName) . " must be a string.";
					break;
				case 'integer':
					$messages[$messageKey] = "The " . ucfirst($fieldName) . " must be an integer.";
					break;
				case 'email':
					$messages[$messageKey] = "The " . ucfirst($fieldName) . " must be a valid email address.";
					break;
				case 'numeric':
					$messages[$messageKey] = "The " . ucfirst($fieldName) . " must be a number.";
					break;
				case 'boolean':
					$messages[$messageKey] = "The " . ucfirst($fieldName) . " must be true or false.";
					break;
				case 'date':
					$messages[$messageKey] = "The " . ucfirst($fieldName) . " must be a valid date.";
					break;
				case 'unique':
					$messages[$messageKey] = "The " . ucfirst($fieldName) . " has already been taken.";
					break;
				case 'exists':
					$messages[$messageKey] = "The selected " . ucfirst($fieldName) . " is invalid or does not exist.";
					break;
				case 'in':
					$messages[$messageKey] = "The selected " . ucfirst($fieldName) . " is invalid.";
					break;
				default:
					if (str_starts_with($rule, 'max:')) {
						$max = explode(':', $rule)[1];
						$messages[$messageKey] = "The " . ucfirst($fieldName) . " may not be greater than {$max} characters.";
					} elseif (str_starts_with($rule, 'min:')) {
						$min = explode(':', $rule)[1];
						$messages[$messageKey] = "The " . ucfirst($fieldName) . " must be at least {$min} characters.";
					}
					break;
			}
		}
		
		return $messages;
	}

	public function generateValidationCode(): string
	{
		$rules = $this->generateValidationRules();
		$messages = $this->generateValidationMessages();
		
		$rulesString = $this->arrayToString($rules);
		$messagesString = $this->arrayToString($messages);
		
		return "return validator(\n\t\t\t\$data,\n\t\t\t{$rulesString},\n\t\t\t\t{$messagesString}\n\t\t)->validate();";
	}

	protected function arrayToString(array $array): string
	{
		if (empty($array)) {
			return '[]';
		}
		
		$lines = ['['];
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				$valueString = $this->arrayToString($value);
			} else {
				$valueString = '"' . addslashes($value) . '"';
			}
			$lines[] = "\t\t\t\t'{$key}' => {$valueString},";
		}
		$lines[] = "\t\t\t]";
		
		return implode("\n", $lines);
	}
}