<?php
// ============================================================
// Ready-made WhatsApp templates (one-click submit to Meta).
// {{1}} is filled with the contact's name when sending; {biz} is replaced
// with the account's company name when the template is created.
// Written to pass Meta review: no variable at the very start/end,
// clear purpose, opt-out footer on marketing messages.
// ============================================================

function templateLibrary(): array {
    $stop = 'Reply STOP to unsubscribe';
    return [
        'welcome' => ['title' => 'Welcome new customer', 'icon' => '👋', 'category' => 'MARKETING', 'name' => 'welcome_message',
            'body' => "Hi {{1}}, welcome to {biz}! 🎉\n\nThank you for connecting with us on WhatsApp. Reply to this message anytime and our team will be happy to help you.",
            'footer' => $stop, 'buttons' => []],
        'festive_offer' => ['title' => 'Festive / sale offer', 'icon' => '🎁', 'category' => 'MARKETING', 'name' => 'festive_offer',
            'body' => "Hi {{1}}, our festive sale is now live at {biz}! 🎁\n\nEnjoy special discounts this week only. Reply YES to know more or visit us today.",
            'footer' => $stop, 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Yes, tell me more']]],
        'we_miss_you' => ['title' => 'Win back old customers', 'icon' => '💚', 'category' => 'MARKETING', 'name' => 'we_miss_you',
            'body' => "Hi {{1}}, we miss you at {biz}! 💚\n\nCome back this week and enjoy something special picked just for you. Reply to this message to know more.",
            'footer' => $stop, 'buttons' => []],
        'feedback' => ['title' => 'Ask for feedback', 'icon' => '⭐', 'category' => 'MARKETING', 'name' => 'feedback_request',
            'body' => "Hi {{1}}, thank you for choosing {biz}! 🙏\n\nHow was your experience? Please reply with a rating from 1 to 5. Your feedback helps us serve you better.",
            'footer' => $stop, 'buttons' => []],
        'order_confirmed' => ['title' => 'Order confirmation', 'icon' => '✅', 'category' => 'UTILITY', 'name' => 'order_confirmation',
            'body' => "Hi {{1}}, your order with {biz} is confirmed. ✅\n\nWe will share the delivery details as soon as it is dispatched. Thank you for shopping with us.",
            'footer' => '', 'buttons' => []],
        'order_dispatched' => ['title' => 'Order dispatched', 'icon' => '🚚', 'category' => 'UTILITY', 'name' => 'order_dispatched',
            'body' => "Hi {{1}}, good news! Your order from {biz} has been dispatched and will reach you soon. 🚚\n\nReply to this message if you need any help with your delivery.",
            'footer' => '', 'buttons' => []],
        'payment_reminder' => ['title' => 'Payment reminder', 'icon' => '💳', 'category' => 'UTILITY', 'name' => 'payment_reminder',
            'body' => "Hi {{1}}, this is a friendly reminder from {biz} that a payment on your account is due.\n\nPlease pay at your convenience, or reply here if you have already paid.",
            'footer' => '', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Already paid']]],
        'appointment' => ['title' => 'Appointment reminder', 'icon' => '📅', 'category' => 'UTILITY', 'name' => 'appointment_reminder',
            'body' => "Hi {{1}}, this is a reminder of your upcoming appointment with {biz}.\n\nPlease confirm your visit or let us know if you need to reschedule.",
            'footer' => '', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'Confirm'], ['type' => 'QUICK_REPLY', 'text' => 'Reschedule']]],
    ];
}

/** Library template with the business name filled in. */
function libraryTemplate(string $key, string $business): ?array {
    $t = templateLibrary()[$key] ?? null;
    if (!$t) return null;
    $biz = trim(preg_replace('/[{}\n\r]+/', ' ', $business)) ?: 'our store';
    $t['body'] = str_replace('{biz}', mb_substr($biz, 0, 60), $t['body']);
    return $t;
}
