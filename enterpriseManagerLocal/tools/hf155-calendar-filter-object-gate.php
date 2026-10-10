<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$js=file_get_contents($root.'/products/calendar/assets/js/calendar.js');
$php=file_get_contents($root.'/products/calendar/api/calendar_handler.php');
$checks=[
 'FILTER_STATE_NULL'=>str_contains($js,'filterObject: null'),
 'INITIAL_RENDER_NULL'=>str_contains($js,'render(null);'),
 'RENDER_ARGUMENT'=>str_contains($js,'function render(filterObject)'),
 'PUBLIC_RENDER'=>str_contains($js,'render: function (filterObject)'),
 'NULL_ALL'=>str_contains($js,'state.filterObject === null ? state.lessons'),
 'GENERIC_FIELDS'=>str_contains($js,'Object.keys(state.filterObject).every'),
 'OPERATORS'=>str_contains($js,"hasOwnProperty.call(expected, 'contains')") && str_contains($js,'expected.between'),
 'RAW_DATAFORM_FIELDS'=>str_contains($php,"'filter_data'=>\$d"),
 'DERIVED_FILTER_FIELDS'=>str_contains($php,"\$out['filter_data']=array_merge")
];
$ok=true;foreach($checks as $name=>$pass){echo $name.' '.($pass?'PASS':'FAIL').PHP_EOL;$ok=$ok&&$pass;}exit($ok?0:1);
