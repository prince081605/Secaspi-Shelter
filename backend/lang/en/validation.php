<?php

// Only overrides: everything else comes from Laravel's own validation messages. These are the
// field names staff and visitors see in error messages, where the column name reads badly
// ("The spent at cannot be in the future" → "The date spent cannot be in the future").

return [
    'attributes' => [
        'spent_at' => 'date spent',
        'valid_id_type' => 'valid ID type',
        'valid_id_image' => 'valid ID photo',
        'requested_date' => 'visit date',
        'num_visitors' => 'number of visitors',
        'follow_up_date' => 'follow-up date',
        'next_due' => 'next due date',
        'company_website' => 'website',
    ],
];
