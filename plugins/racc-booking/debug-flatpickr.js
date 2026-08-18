/**
 * DEBUG SCRIPT - Test Flatpickr on Reschedule Page
 * 
 * Paste this in browser console on reschedule page to debug
 */

console.log('=== FLATPICKR DEBUG ===');

// 1. Check if jQuery loaded
console.log('jQuery loaded:', typeof $ !== 'undefined');

// 2. Check if Flatpickr loaded
console.log('Flatpickr loaded:', typeof flatpickr !== 'undefined');

// 3. Check if date field exists
console.log('Date field exists:', $('#racc-reschedule-date').length > 0);

// 4. Check field attributes
if ($('#racc-reschedule-date').length) {
    var $field = $('#racc-reschedule-date');
    console.log('Field ID:', $field.attr('id'));
    console.log('Field classes:', $field.attr('class'));
    console.log('Field readonly:', $field.prop('readonly'));
    console.log('Field disabled:', $field.prop('disabled'));
}

// 5. Try to initialize Flatpickr manually
if (typeof flatpickr !== 'undefined' && $('#racc-reschedule-date').length) {
    console.log('Attempting manual Flatpickr init...');
    try {
        var testPicker = flatpickr('#racc-reschedule-date', {
            dateFormat: 'Y-m-d',
            minDate: 'today',
            disableMobile: true,
            clickOpens: true
        });
        console.log('SUCCESS! Flatpickr initialized:', testPicker);
        console.log('Try clicking the date field now!');
    } catch(e) {
        console.error('ERROR initializing Flatpickr:', e);
    }
} else {
    console.error('Cannot initialize: Flatpickr or field not available');
}

// 6. Add manual click test
$('#racc-reschedule-date').on('click', function() {
    console.log('Date field was clicked!');
});

console.log('=== END DEBUG ===');
