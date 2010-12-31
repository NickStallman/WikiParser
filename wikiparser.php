<?php

class wiki_parser
{
	function __construct($text)
	{
		$master = new wiki_section($text);
		//var_dump($master->flatten());
		$master->flatten();
	}
}

class wiki_section
{
	public $sections = array();
	public $code = '';

	private $open = array(
		'&lt;!--',
		'&lt;ref&gt;',
		'&lt;ref ',
		'[[',
		'[',
		'{{',
		'{|',
		'&lt;br',
		'\'\'\'\'\'',
		'\'\'\'',
		'\'\'',
		'&lt;small&gt;',
		);
	private $close = array(
		'--&gt;',
		'&lt;/ref&gt;',
		array('/&gt;', '&lt;/ref&gt;'),
		']]',
		']',
		'}}',
		'|}',
		'&gt;',
		'\'\'\'\'\'',
		'\'\'\'',
		'\'\'',
		'&lt;/small&gt;',
		);
	private $func = array(
		'handleNull',
		'handleNull',
		'handleNull',
		'handleLink',
		'handleLink',
		'handleTemplate',
		'handleNull',
		'handleNull',
		'handleEmphasis',
		'handleEmphasis',
		'handleEmphasis',
		'handleHTML',
		);
	private $skip_check = array(
		8 => array(8, 9, 10),
		9 => array(8, 9, 10),
		10 => array(8, 9, 10),
		);
	private $skip_inner_check = array(
		0 => true,
		1 => true,
		2 => true,
		);

	private $bad_headings = array(
		'see also',
		'references',
		'external links',
		);

	private $last_close = '';
	private $ch = false;

	function __construct($in, $level = 1)
	{
		$this->code = $this->handleSections($in, $level + 1);
		if ($level > 1)
		{
			$this->code = $this->handleInline($this->code);
			$this->code = $this->handleLines(trim($this->code));
		}
	}

	function flatten()
	{
		foreach ($this->sections as $hash => $section)
		{
			$this->code = str_replace($hash, $section->flatten(), $this->code);
		}

		$this->sections = array();

		return $this->code;
	}

	function handleSections($input, $level = 2)
	{
		$look_for = str_repeat('=', $level);

		$title = '';
		$buffer = '';
		$lines = explode("\n", $input);
		$output = '';

		foreach ($lines as $line)
		{
			if (
				substr($line, 0, $level) == $look_for &&
				substr($line, $level, 1) != '=' &&
				substr($line, -$level) == $look_for &&
				substr($line, -$level - 1, 1) != '='
				)
			{
				if (!empty($title) && in_array(strtolower($title), $this->bad_headings))
				{
					$title = '';
					$buffer = '';
				}

				if (!empty($title))
				{
					$output .= "<h$level>$title</h$level>\n";
				}
				
				if (!empty($buffer))
				{
					$uniq = $this->make_uniq();
					$this->sections[$uniq] = new wiki_section($buffer, $level);
					$output .= "$uniq\n";
				}

				$buffer = '';
				$title = trim(substr($line, $level, -$level));
			} else {
				if (!empty($buffer))
					$buffer .= "\n";
				$buffer .= $line;
			}
		}

		if (!empty($title) && in_array(strtolower($title), $this->bad_headings))
		{
			$title = '';
			$buffer = '';
		}

		if (!empty($title) && !empty($buffer))
		{
			if (!empty($title))
			{
				$output .= "<h$level>$title</h$level>\n";
			}

			if (!empty($buffer))
			{
				$uniq = $this->make_uniq();
				$this->sections[$uniq] = new wiki_section($buffer, $level);
				$output .= "$uniq\n";
			}
		} elseif (empty($output) && !empty($buffer)) {
			$output = $buffer;
		}

		return $output;
	}

	function handleInline($line, $depth = 0)
	{
		$offset = 0;
		$loop = 0;
		while (true)
		{
			$pos = 99999999999;
			$pos2 = 0;
			$tagid = false;
			foreach ($this->open as $tmp2 => $tmp3)
			{
				$tmp = strpos($line, $tmp3, $offset);
				if ($tmp === false)
					continue;

				$tmp4 = $this->find_close($line, $tmp2, $tmp + strlen($tmp3));
				if ($tmp4 === false)
					continue;

				if ($tmp < $pos)
				{
					$pos = $tmp;
					$pos2 = $tmp4;
					$tagid = $tmp2;
				}
			}

			if ($tagid === false)
				break;

			$offset = $pos;
			$tag = $this->open[$tagid];
			$oldbits = $line;

			do
			{
				if (isset($this->skip_inner_check[$tagid]))
					break;

				$complete = true;
				$skip_to = 9999999;
				$skip_to2 = 0;
				$sym = '';
				foreach ($this->open as $symbol_id => $symbol)
				{
					if (isset($this->skip_check[$tagid]) && in_array($symbol_id, $this->skip_check[$tagid]))
						continue;

					$tmp = strpos($line, $symbol, $pos + strlen($tag));
					if ($tmp === false)
						continue;

					$tmp2 = $this->find_close($line, $symbol_id, $tmp + strlen($symbol));
					if ($tmp2 === false)
						continue;

					if ($tmp < $pos2)
					{
						$sym = $symbol;
						$complete = false;
						$skip_to = $tmp;
						$skip_to2 = $tmp2;
					}
				}

				if ($complete === false)
				{
					$line = substr($line, 0, $skip_to).$this->handleInline(substr($line, $skip_to), $depth + 1);
				}
			} while ($complete === false);

			$pos2 = $this->find_close($line, $tagid, $pos + strlen($tag));
			if ($pos2 === false)
				continue;

			$bit = substr($line, $pos, $pos2 - $pos + strlen($this->last_close));

			$ret = call_user_func(array($this, $this->func[$tagid]), $bit);

			$line = ($pos > 0 ? substr($line, 0, $pos) : '').$ret.substr($line, $pos2 + strlen($this->last_close));
		}

		return $line;
	}

	function find_close($line, $open_tag, $offset)
	{
		if (is_array($this->close[$open_tag]))
		{
			$smallest = 99999999;
			$found = false;
			$found_tag = '';
			foreach ($this->close[$open_tag] as $tag)
			{
				$tmp = strpos($line, $tag, $offset);
				if ($tmp !== false && $tmp < $smallest)
				{
					$found = true;
					$smallest = $tmp;
					$found_tag = $tag;
				}
			}

			if ($found)
			{
				$this->last_close = $found_tag;
				return $smallest;
			} else
				return false;
		} else {
			$this->last_close = $this->close[$open_tag];
			return strpos($line, $this->close[$open_tag], $offset);
		}
	}

	function handleNull($in)
	{
		return '';
	}

	function handleLink($input)
	{
		if (substr($input, 0, 2) == '[[')
		{
			if (preg_match('|\[\[(([^\]]*?)\:)?([^\]]*?)(\|([^\]]*?))?\]\]|', $input, $matches))
			{
				$href = $matches[3];
				$title = empty($matches[5]) ? $matches[3] : $matches[5];
				$namespace = strtolower($matches[2]);

				if ($namespace == 'file' || $namespace == 'image')
				{
					if (count($matches) < 5)
					{
						var_dump($input, $matches);
						die();
					}
					return $this->handleImage($matches);
				} else {
					return $title;
				}

				$result = mysql_query('SELECT suburb_id FROM `wiki` join suburbs on suburbs.wiki_id = wiki.wiki_id WHERE name like "'.esc(html_entity_decode($href)).'"');
				if ($row = mysql_fetch_array($result))
				{
					return '<a href="/go/'.$row['suburb_id'].'">'.$title.'</a>';
				} else {
					return $title;
				}
			} else {
				return '**Booboo on line '.__LINE__.'**';
			}	
		} else {
			$input = substr($input, 1, -1);
			$bits = explode(' ', $input, 1);
			
			return '<a href="'.$bits[0].'" target="_blank" rel="nofollow">'.(empty($bits[1]) ? $bits[0] : $bits[1]).'</a>';
		}
	}

	function handleImage($matches)
	{
		$properties = array(
			'alt' => '',
			'caption' => '',
			'align' => 'right',
			'thumb' => false,
			'width' => 0,
			'height' => 0,
			);
		$file = $matches[3];
		$bits = explode('|', $matches[5]);

		foreach ($bits as $bit)
		{
			$command = $bit;
			$param = '';
			$pos = strpos($bit, '=');
			if ($pos !== false)
			{
				$command = substr($bit, 0, $pos);
				$param = substr($bit, $pos + 1);
			}

			if (substr($command, -2) == 'px')
			{
				$param = substr($command, 0, -2);
				$command = 'px';
			}

			switch ($command)
			{
				case 'thumb':
					$properties['thumb'] = true;
					$properties['width'] = 220;
					break;
				case 'center':
					$properties['align'] = 'center';
					break;
				case 'left':
					$properties['align'] = 'left';
					break;
				case 'right':
					$properties['align'] = 'right';
					break;
				case 'none':
					$properties['align'] = 'none';
					break;
				case 'px':
					$dim = explode('x', $param);
					$properties['width'] = empty($dim[0]) ? 0 : intval($dim[0]);
					$properties['height'] = isset($dim[1]) ? intval($dim[1]) : 0;
					break;
				case 'alt':
					$properties['alt'] = $param;
					break;
				case 'upright':
					if (empty($param))
						$param = 0.75;
					$properties['width'] = round(22 * $param) * 10;
					break;
				default:
					$properties['caption'] = $bit;
			}
		}

		$beginning = '';
		$end = '';

		if ($properties['align'] == 'center')
		{
			$beginning .= '<div class="center">';
			$end = '</div>'.$end;
		}

		$beginning .= '<div class="thumb t'.$properties['align'].'"><div class="thumbinner"'.($properties['width'] > 0 ? ' style="width:'.$properties['width'].'px"' : '').'>';
		$end = '</div></div>'.$end;

		$img_id = $this->getImage($file, $properties);
		if ($img_id == 0)
			return '';
		
		$img = '<img src="/img/'.$properties['width'].'/'.$properties['height'].'/'.$img_id.'.jpg"';
		if (!empty($properties['alt']))
			$img .= ' alt="'.$properties['alt'].'"';
		if ($properties['thumb'] === false)
			$img .= ' title="'.$properties['caption'].'"';
		$img .= '>';
		
		return $beginning.$img. $end;
	}

	function getImage($file, $properties, $namespace = 'commons', $thumb = true)
	{
		$file = trim(str_replace(array(' ', chr(0xe2).chr(0x80).chr(0x8e)), array('_', ''), $file));
		$hash = md5($file);
		$img_id = 0;
		$result = mysql_query('SELECT image_id FROM images WHERE raw_name = "'.$hash.'"');

		if ($row = mysql_fetch_array($result))
		{
			return $row['image_id'];
		} else {
			$width = $properties['width'] > 640 ? $properties['width'] : 640;
			if ($thumb)
				$url = 'http://upload.wikimedia.org/wikipedia/'.$namespace.'/thumb/'.substr($hash, 0, 1).'/'.substr($hash, 0, 2).'/'.$file.'/'.$width.'px-'.$file;
			else
				$url = 'http://upload.wikimedia.org/wikipedia/'.$namespace.'/'.substr($hash, 0, 1).'/'.substr($hash, 0, 2).'/'.$file;

			$filename = 'imgs/'.$hash;
			$data = $this->fetch_url($url);
			if (stripos($data, 'is the requested width bigger than the source?') !== false)
				return $this->getImage($file, $properties, $namespace, false);
			file_put_contents($filename, $data);

			$img = getimagesize($filename);
			if ($img[0] == 0 || $img[1] == 0)
			{
				if ($namespace == 'commons')
					return $this->getImage($file, $properties, 'en', $thumb);

				@unlink($filename);
				return 0;
			}
			mysql_query("INSERT INTO images (`raw_name`, `filename`, `type`, `width`, `height`) VALUES ('$hash', '$file', {$img[2]}, {$img[0]}, {$img[1]})");
			return mysql_insert_id();
		}
	}

	function handleTemplate($input)
	{
		$input = substr($input, 2, -2);
		$bits = explode('|', $input);
		$ret = '';

		$type = explode(' ', $bits[0]);
		$type = strtolower(trim($type[0]));

		switch ($type)
		{
			case 'col-begin':
			case 'col-break':
			case 'col-end':
				break;
			case 'pron-en':
				return 'pronounced '.$bits[1];
				break;
			case 'convert':
				break;
		}

		return $ret;
	}

	function handleEmphasis($line)
	{
		$len = strspn($line, '\'');
		$text = trim(substr($line, $len, -$len));

		switch ($len)
		{
			case 2:
				return '<em>'.$text.'</em>';
			case 3:
			case 4:
				return '<strong>'.$text.'</strong>';
			case 5:
				return '<strong><em>'.$text.'</em></strong>';
		}

		return $text;
	}

	function handleLines($input)
	{
		$buffer = '';
		$offset = 0;
		$listlevel = array();
		$lists = array(1 => '*', 2 => '#');
		$p = false;

		while ($offset < strlen($input))
		{
			$pos = strpos($input, "\n", $offset);
			$buffer = '';

			$newblock = false;
			$addp = false;

			if ($pos === false)
			{
				$newblock = true;
				$pos = strlen($input);
			}

			$buffer = trim(substr($input, $offset, $pos - $offset));

			if (empty($buffer) && $p)
				$newblock = true;
			elseif (!empty($buffer))
			{
				if (!(substr($buffer, 0, 2) == '<d' || substr($buffer, 0, 2) == '<h'))
				{
					if (!$p)
					{
						$addp = true;
						$p = true;
					}
				}

				if (substr($buffer, 0, 1) == '*' || substr($buffer, 0, 1) == '#')
				{
					$curlist = array();
					$bit = 0;
					$len = strlen($buffer);
					$chunk = '';
					while ($bit < $len)
					{
						$chunk = substr($buffer, $bit, 1);
						$idx = array_search($chunk, $lists);

						if ($idx)
							$curlist[] = $idx;
						else
							break;
						$bit++;
					}

					$chunk = trim(substr($buffer, $bit + 1));
					$buffer = '';

					$similar_depth = 0;
					foreach ($listlevel as $depth => $t)
					{
						if (isset($curlist[$depth]) && $curlist[$depth] == $t)
							$similar_depth++;
						else
							break;
					}

					$undo = array_splice($listlevel, $similar_depth);
					while ($type = array_pop($undo))
					{
						if ($type == 1)
							$buffer .= "</ul>\n";
						if ($type == 2)
							$buffer .= "</ol>\n";
					}

					$do = array_splice($curlist, $similar_depth);
					while ($type = array_pop($do))
					{
						$listlevel[] = $type;
						if ($type == 1)
							$buffer = "<ul>\n";
						if ($type == 2)
							$buffer = "<ol>\n";
					}

					$buffer .= "<li>$chunk</li>";
				}

				if ($addp)
					$buffer = '<p>'.$buffer;
			}

			if ($newblock)
			{
				if (!empty($listlevel))
				{
					while ($type = array_pop($listlevel))
					{
						if ($type == 1)
							$buffer .= "</ul>\n";
						if ($type == 2)
							$buffer .= "</ol>\n";
					}
				}

				if ($p)
				{
					$buffer .= "</p>\n";
					$p = false;
				}
			}

			$input = ($offset > 0 ? substr($input, 0, $offset) : '').$buffer.substr($input, $pos);
			$offset += strlen($buffer) + 1;
		}

		return $input;
	}

	function handleHTML($input)
	{
		$pos1 = strpos($input, '&gt;');
		$first = substr($input, 0, $pos1 + 4);
		$rest = substr($input, $pos1 + 4);
		$input = html_entity_decode($first).$rest;
		
		$pos2 = strrpos($input, '&lt;', $pos1);
		if ($pos2 > 0)
		{
			$rest = substr($input, 0, $pos2);
			$last = substr($input, $pos2);
			$input = $rest.html_entity_decode($last);
		}

		return $input;
	}

	function fetch_url($url)
	{
		if ($this->ch === false)
			$this->ch = curl_init();

		curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($this->ch, CURLOPT_ENCODING, 'gzip,deflate');
                curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 15);
                curl_setopt($this->ch, CURLOPT_TIMEOUT, 20);
                curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($this->ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.3) Gecko/20100401 Firefox/3.6.3');
                curl_setopt($this->ch, CURLOPT_URL, $url);

                return curl_exec($this->ch);
	}

	function make_uniq()
	{
		return sha1(uniqid('wikiparse', true));
	}
}

