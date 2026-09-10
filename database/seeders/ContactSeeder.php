<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Marvel\Database\Models\Contact;

class ContactSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = [
            'Order inquiry',
            'Product question',
            'Shipping status',
            'Return request',
            'Payment issue',
            'Account support',
            'Website feedback',
            'Bulk order request',
            'Warranty question',
            'General support',
        ];

        $messages = [
            'I would like more details about this item and its availability.',
            'Please help me with the latest update on my order.',
            'Can you confirm the delivery timeline for my purchase?',
            'I need support with a recent payment attempt.',
            'Please share more information about your return policy.',
            'I have a question about product specifications and features.',
            'Could you assist me with my account login issue?',
            'I am interested in placing a larger order for my store.',
            'Please let me know if this product comes with a warranty.',
            'I want to share feedback about my shopping experience.',
        ];

        $names = ['Ahmed Hassan', 'Sara Ali', 'Mohamed Ibrahim', 'Nora Khalid', 'Omar Youssef', 'Layla Mahmoud', 'Khaled Mostafa', 'Dina Sherif'];

        for ($i = 1; $i <= 50; $i++) {
            $subject = $subjects[($i - 1) % count($subjects)] . ' #' . $i;
            $message = $messages[($i - 1) % count($messages)];

            Contact::create([
                'name' => $names[($i - 1) % count($names)],
                'email' => 'customer' . $i . '@example.com',
                'subject' => $subject,
                'message' => $message . ' Ref: ' . Str::upper(Str::random(6)),
                'is_read' => $i % 3 === 0,
                'is_replay' => $i % 10 === 0,
            ]);
        }
    }
}