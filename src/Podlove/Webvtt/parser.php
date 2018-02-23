<?php
namespace Podlove\Webvtt;

class ParserException extends \Exception {

}

/**
 * NOTES
 * 
 * - build a generic "error message, position, line, expected ... but got ..." reporting routine
 * - this is great: https://w3c.github.io/webvtt/
 */
class Parser {

	private $pos;
	private $line;
	private $content;

	private $cues;

	const LF    = "\u{000A}";
	const FF    = "\u{000C}";
	const CR    = "\u{000D}";
	const SPACE = "\u{0020}";
	const TAB   = "\u{0009}";

	public function parse($content)
	{
		$this->pos = 0;
		$this->line = 1;
		$this->content = $content;
		$this->cues = [];

		// NULL -> REPLACEMENT
		$this->content = str_replace("\u{0000}", "\u{FFFD}", $this->content);
		// CRLF -> LF
		$this->content = str_replace(self::CR . self::LF, self::LF, $this->content);
		// CR -> LF
		$this->content = str_replace(self::CR, self::LF, $this->content);

		$this->skip_bom();
		$this->skip_signature();
		$this->skip_signature_trails();
		$this->skip_line_terminator();
		// todo: there may be a header in between here
		$this->skip_line_terminator();

		if ($this->is_end_reached()) {
			return ['cues' => []];
		}

		$block = $this->read_block();
		$this->cues[] = $block;

		// error_log(print_r(substr($this->content, $this->pos), true));

		return [
			'cues' => $this->cues
		];
	}

	/**
	 * Reads and returns current block.
	 * 
	 * Advances $pos and $line.
	 * 
	 * @return string
	 */
	private function read_block()
	{
		$block_line_no = 0;
		$start = 0;
		$end = 0;
		$seen_arrow = false;
		$buffer = "";

		do {
			$prev_pos = $this->pos;
			$line = $this->read_line();
			$block_line_no++;

			if (strpos($line, "-->") && !$seen_arrow) {
				if ($block_line_no > 2) {
					break;
				}
				$seen_arrow = true;

				$this->pos = $prev_pos;
				$this->skip_whitespace();
				$start = $this->read_timestamp();
				$this->skip_whitespace();
				$this->skip_arrow();
				$this->skip_whitespace();
				$end = $this->read_timestamp();
			} else {
				$buffer .= $line;
			}
		} while (!$this->is_end_reached());

		return [
			'start' => $start,
			'end' => $end,
			'text' => $buffer
		];
	}

	private function skip_whitespace()
	{
		$whitespace = [
			self::TAB,
			self::LF,
			self::FF,
			self::CR,
			self::SPACE,
		];
		while (in_array($this->next(), $whitespace) && !$this->is_end_reached()) {
		    $this->pos++;
		}
	}

	private function skip_arrow()
	{
		if ($this->next(3) == '-->') {
			$this->pos += 3;
		} else {
			throw new ParserException("Expected \"-->\"  between Timestamps, got " . $this->next() . " at line {$this->line}, pos {$this->pos}");
		}		
	}

	private function read_timestamp()
	{
		$most_significant_units = 'minutes';

		if (!self::is_ascii_digit($this->next())) {
			throw new ParserException("Expected Timestamp, got " . $this->next() . " at line {$this->line}, pos {$this->pos}");
		}

		$int = $this->read_integer();
		$value1 = $int['int'];

		if ($int['int'] > 59 || strlen($int['str']) !== 2) {
			$most_significant_units = 'hours';
		}

		$this->skip_colon();

		$value2 = $this->read_n_digit_integer(2);

		if ($most_significant_units === 'hours' || !$this->is_line_end_reached() && $this->next() == ':') {
			$this->skip_colon();
			$value3 = $this->read_n_digit_integer(2);
		} else {
			$value3 = $value2;
			$value2 = $value1;
			$value1 = 0;
		}

		$this->skip_full_stop();

		$value4 = $this->read_n_digit_integer(3);

		if ($value2 > 59) {
			throw new ParserException("Error when parsing Timestamp: minutes > 59 at line {$this->line}");
		}
		if ($value3 > 59) {
			throw new ParserException("Error when parsing Timestamp: seconds > 59 at line {$this->line}");
		}

		return $value1*60*60 + $value2*60 + $value3 + $value4/1000;
	}

	private static function is_ascii_digit($digit)
	{
		return preg_match("/^[0-9]$/", $digit) === 1;
	}

	private function read_integer()
	{
		if (!self::is_ascii_digit($this->next())) {
			throw new ParserException("Error when parsing Timestamp: expected integer at line {$this->line}");
		}

		$buf = "";
		do {
			$buf .= $this->next();
			$this->pos++;
		} while (self::is_ascii_digit($this->next()));
		return [
			'str' => $buf,
			'int' => intval($buf, 10)
		];
	}

	private function read_n_digit_integer($n)
	{
		$int = $this->read_integer();

		if (strlen($int['str']) !== $n) {
			throw new ParserException("Expected $n-digit integer in Timestamp at line {$this->line}");
		}

		return $int['int'];
	}

	private function skip_full_stop()
	{
		if ($this->next() !== '.' || $this->is_end_reached()) {
			throw new ParserException("Expected FULL STOP (.) in Timestamp at line {$this->line}");
		}
		$this->pos++;			
	}

	private function skip_colon()
	{
		if ($this->next() !== ':' || $this->is_end_reached()) {
			throw new ParserException("Expected COLON (:), got " . $this->next() . " in Timestamp at line {$this->line}");
		}
		$this->pos++;		
	}

	/**
	 * Reads and returns current line.
	 * 
	 * Advances $pos and $line.
	 * 
	 * @return string
	 */
	private function read_line()
	{
		$line = "";
		
		while (($c = $this->next()) !== self::LF && !$this->is_end_reached()) {
		    $this->pos++;
		    $line .= $c;
		}

		if ($this->next() === self::LF) {
			$this->pos++;
		} else {
			throw new ParserException("Unexpected end of file on line $line");
		}

		return $line;
	}

	private function next($length = 1, $offset = 0)
	{
		return substr($this->content, $this->pos + $offset, $length);
	}

	private function skip_bom()
	{
		$bom = chr(239) . chr(187) . chr(191);
		
		if ($this->next(3) == $bom) {
			$this->pos += 3;
		}
	}

	private function skip_signature()
	{
		if ($this->next(6) == "WEBVTT") {
			$this->pos += 6;
		} else {
			throw new ParserException("Missing WEBVTT at beginning of file.");
		}		
	}

	private function skip_signature_trails()
	{
		if (in_array($this->next(), [self::SPACE, self::TAB])) {
			$this->pos++;
			while ($this->next() !== self::LF && !$this->is_end_reached()) {
			    $this->pos++;
			}
		}
	}

	private function is_end_reached() {
		return $this->pos + 1 >= strlen($this->content);
	}

	private function is_line_end_reached() {
		return $this->next() === self::LF;
	}

	private function skip_line_terminator()
	{
		if ($this->next() === self::LF) {
			$this->pos += 1;
			$this->line++;
		} else {
			throw new ParserException("Expected line terminator at line {$this->line}");
		}
	}
}
