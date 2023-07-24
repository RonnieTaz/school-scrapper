<?php

return [
    'necta' => [
        'site_url' => 'https://www.necta.go.tz',
        'base_results_url' => 'https://onlinesys.necta.go.tz/results/{year}/',
        'results_url' => [
            'primary' => "psle/psle.htm",
            'secondary' => "csee/index.htm",
            'advanced_secondary' => "acsee/index.htm",
            'custom' => [
                ///format
                /// year=>[
                ///     'primary' => URL
                ///     'secondary' => URL
                ///     'advanced_secondary' => URL
                /// ]
            ]
        ],
    ],
];
