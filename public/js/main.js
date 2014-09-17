
$(document).ready(function() {
    $('.alert').fadeIn();
    setTimeout(function() {
        $('.alert').fadeOut();
    }, 5000);
});

function fuzzyDate(element, time) {
    $(element).text(prettyDate(time));
    setInterval(function() {
        $(element).text(prettyDate(time));
    }, 1000);
}

function prettyDate(time) {
    time = time * 1000;
    var date = new Date(time),
            diff = (((new Date()).getTime() - date.getTime()) / 1000),
            day_diff = Math.floor(diff / 86400);

    if (isNaN(day_diff) || day_diff < 0)
        return;

    var weeks = Math.ceil(day_diff / 7);
    return day_diff === 0 && (
            diff < 60 && "just now" ||
            diff < 120 && "1 minute ago" ||
            diff < 3600 && Math.floor(diff / 60) + " minutes ago" ||
            diff < 7200 && "1 hour ago" ||
            diff < 86400 && Math.floor(diff / 3600) + " hours ago") ||
            day_diff === 1 && "Yesterday" ||
            day_diff < 7 && day_diff + " days ago" ||
            day_diff < 31 && weeks > 1 && weeks + " weeks ago" ||
            day_diff < 31 && weeks === 1 && weeks + " week ago" ||
            date.toLocaleDateString();
}