<?php
// Debug runner: exercise resolve/guide/general without hitting the endpoint.
$root = 'C:\Users\Magdyn Pc\OneDrive\Desktop\Naveen\'s file\MoneyWise-main\MoneyWise-main';
require $root . '\api\config.php';
require $root . '\services\FinanceData.php';
require $root . '\services\AI_Lang.php';
require $root . '\services\AIService.php';
require $root . '\api\__ai_defs.php';

$GLOBALS['u'] = ['id' => 1];

$qs = [
  'compare this year with last year',
  'which month did i spend the most',
  'what is my average monthly spending',
  'which category increased the most',
  'show my spending trend',
  'give me suggestions to control my expenses',
  'how can i reduce my expenses',
  'where do i spend the most',
  'what are my biggest expenses',
  'how much i spend vegtables',
  'what is my balance',
  'hello',
  'what about last month',
  'how much did i spend on food',
  'which month spent most',
  'give me a summary of my spending',
  'tell me a joke',
  '12 + 7',
  'what is 5 x 6',
  'write java code to print hello',
  'write python to add two numbers',
  'what is the capital of france',
  'correct this sentence: i is going to school',
];
foreach ($qs as $q) {
  $fin = ai_resolve(1, $q, 'en');
  $composed = ai_compose_local($fin, 'en');
  $guide = ai_guide($q, 'en');
  $gen = ai_general($q, 'en');
  $intent = $fin['intent'] ?? '';
  $g = $guide ? mb_substr($guide['reply'], 0, 35) : 'null';
  $ge = $gen ? mb_substr($gen['reply'], 0, 35) : 'null';
  echo "Q: $q\n   -> intent=$intent | guide=$g | general=$ge\n   -> reply: " . str_replace("\n", ' | ', $composed) . "\n\n";
}