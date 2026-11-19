<?php


namespace Marvel\Otp;

use InvalidArgumentException;

class Result
{

	/**
	 * @var bool
	 */
	private $valid;

	/**
	 * @var array
	 */
	private $errors;

	/**
	 * @var string
	 */
	private $id;

	/**
	 * True when the failure came from the PROVIDER (outage, refused
	 * dispatch, transport error) rather than from a rejected code or id.
	 * Callers use it to classify provider outages as a failed dispatch
	 * instead of a wrong code — without changing isValid() semantics for
	 * existing consumers.
	 *
	 * @var bool
	 */
	private $providerError = false;

	/**
	 * Result constructor.
	 * @param mixed $value => string $id | array $errors
	 */
	public function __construct($value)
	{
		if (is_string($value)) {
			$this->id = $value;
			$this->valid = true;
		} else if (is_array($value)) {
			$this->errors = $value;
			$this->valid = false;
		} else {
			throw new InvalidArgumentException('Invalid argument: Only string or array allowed.');
		}
	}

	/**
	 * A provider-originated failure: the gateway could not complete its
	 * work (outage, transport error, refused dispatch). Still invalid for
	 * every existing isValid() consumer; additionally classifiable.
	 */
	public static function providerError(array $errors): self
	{
		$result = new self($errors);
		$result->providerError = true;

		return $result;
	}

	/**
	 * @return bool
	 */
	public function isProviderError(): bool
	{
		return $this->providerError;
	}

	/**
	 * @return bool
	 */
	public function isValid(): bool
	{
		return $this->valid;
	}

	/**
	 * @return array
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}

	/**
	 * @return string
	 */
	public function getId(): string
	{
		return $this->id;
	}
}
