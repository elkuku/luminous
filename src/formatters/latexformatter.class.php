<?php

require_once(dirname(__FILE__) . '/../tools/cssconverter.php');

/**
 * LaTeX output formatter for Luminous.
 * 
 * \since  0.5.4
 */
class LuminousFormatterLatex extends LuminousFormatter
{
  
  private $css = null;
  function __construct() { }
  
  function SetTheme($theme)
  {
    $this->css = new CSSConverter();
    $this->css->verbose = false;
    $this->css->Convert($theme);
  }
  
  static function hex2rgb($hex)
  {
    $x = hexdec($hex);
    $b = $x % 256;
    $g = ($x >> 8) % 256;
    $r = ($x >> 16) % 256;
    
    $b /= 255.0;
    $g /= 255.0;
    $r /= 255.0;
    
    $b = round($b, 2);
    $g = round($g, 2);
    $r = round($r, 2);    
    
    $rgb = array($r, $g, $b);
    return $rgb;
  }
  protected function Linkify($src){
    return $src;
  }
  
  function DefineStyleCommands()
  {
    $cmds = array();
    foreach($this->css->GetRules() as $name=>$properties)
    {
      if (!preg_match('/^\w+$/', $name))
        continue;
      $cmd = "{#1}" ;
      if ($this->css->GetValue($name, 'bold') === true)
        $cmd = "{\\textbf$cmd}";
      if ($this->css->GetValue($name, 'italic') === true)
        $cmd = "{\\emph$cmd}";
      if (($col = $this->css->GetValue($name, 'color')) !== false)
      {
        if (preg_match('/^#[a-f0-9]{6}$/i', $col))
        {
          $rgb = $this::hex2rgb($col);
          $col_str = "{$rgb[0]}, {$rgb[1]}, $rgb[2]";
          $cmd = "{\\textcolor[rgb]{{$col_str}}$cmd}";
        }
      }
      $name = str_replace('_', '', $name);
      $name = strtoupper($name);
      $cmds[] = "\\newcommand{\\lms{$name}}[1]$cmd";
    }
    
    if (
      $this->line_numbers && 
      ($col = $this->css->GetValue('code', 'color')) !== false)
    {
      if (preg_match('/^#[a-f0-9]{6}$/i', $col))
      {
        $rgb = $this::hex2rgb($col);
        $col_str = "{$rgb[0]}, {$rgb[1]}, $rgb[2]";
        $cmd = "\\renewcommand{\\theFancyVerbLine}{%
        \\textcolor[rgb]{{$col_str}}{\arabic{FancyVerbLine}}}";
        $cmds[] = $cmd;
      }
    }
    
    return implode("\n", $cmds);
  }
  
  function GetBackgroundColor()
  {
    if (($col = $this->css->GetValue('code', 'bgcolor')) !== false)
    {
      if (preg_match('/^#[a-f0-9]{6}$/i', $col))
        $rgb = $this::hex2rgb($col);
        $col_str = "{$rgb[0]}, {$rgb[1]}, $rgb[2]";
        return "\\pagecolor[rgb]{{$col_str}}";
    }
    return "";
  }
  
  
  
  
  
  function Format($str)
  {
    $out = '';
    
    $verbcmd = "\\begin{Verbatim}[commandchars=\\\\\\{\}";
    if ($this->line_numbers)
      $verbcmd .= ",numbers=left,firstnumber=1,stepnumber=1";
    $verbcmd .= ']';
    $out .= <<<EOF
\documentclass{article}
\usepackage{fullpage}
\usepackage{color}
\usepackage{fancyvrb}
\begin{document}
{$this->DefineStyleCommands()}
{$this->GetBackgroundColor()}

$verbcmd

EOF;

    $s = "";
    $str = preg_replace('%<([^/>]+)>\s*</\\1>%', '', $str);
    $str = str_replace("\t", '  ', $str);
    
    $lines = explode("\n", $str);
    
    if ($this->wrap_length > 0)
    {
      $str = "";
      
      foreach($lines as $i=>$l)
      {
        $this->WrapLine($l, $this->wrap_length);
        $str .= $l;
      }
    }
    
    $str_ = preg_split('/(<[^>]+>)/', $str, -1, 
      PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
    
    $f1 = create_function('$matches', '
      return "\\\lms" . str_replace("_", "", $matches[1]) . "{"; ');
    $f2 =  create_function('$matches', '
      if ($matches[0][0] == "\\\")
        return "{\\\textbackslash}";
      return "\\\" . $matches[0];');
    
    foreach($str_ as $s_)
    {
      if ($s_[0] === '<')
      {
        $s_ = preg_replace('%</[^>]+>%', '}', $s_);
        $s_ = preg_replace_callback('%<([^>]+)>%', $f1
        ,$s_);
      }
      else
      {
        $s_ = str_replace('&gt;', '>', $s_);
        $s_ = str_replace('&lt;', '<', $s_);
        $s_ = str_replace('&amp;', '&', $s_);
        
        $s_ = preg_replace_callback('/[#{}_$\\\&]|&(?=amp;)/', $f2, $s_);
      }
      
      $s .= $s_;
    }
    
    unset($str_);
    
    $s = "\\lmsCODE{" . $s . '}';
    
    
    /* XXX:
     * hack alert: leaving newline literals (\n) inside arguments seems to 
     * leave them being totally ignored. This is a problem for wrapping. 
     * 
     * the current solution is to close all open lms commands before the 
     * newline then reopen them afterwards.
     */
    
    $stack = array();
    $pieces = preg_split('/(\\\lms[^\{]+\{|(?<!\\\)(\\\\\\\\)*[\{\}])/', $s, 
      -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
    foreach($pieces as $k=>&$p)
    {
      if (preg_match('/^\\\lms/', $p))
        $stack[] = "" . $p;
      elseif(preg_match('/^(\\\\\\\\)*\}/', $p))
      {
        array_pop($stack);
      }
      elseif(preg_match('/^(\\\\\\\\)*{/', $p))
        $stack [] = $p;
        
      elseif(strpos($p, "\n") !== false)
      {
        $before = "";
        $after = "";
        foreach($stack as $st_)
        {
          $before .= $st_;
          $after .= '}';
        }        
        $p = str_replace("\n",  "$after\n$before" , $p);
      }
    }
    
    $s = implode('', $pieces);
    
    $out .= $s;
    $out .= <<<EOF
\end{Verbatim}
\end{document}
EOF;

    return $out;
  }
  
}
