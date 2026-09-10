<!DOCTYPE html>
<html>
<head><title>Pusher Test</title></head>
<body>
<h1>Pusher Test</h1>
<div id="status">Connecting...</div>
<button onclick="testBroadcast()">Test Broadcast</button>
<script src="https://js.pusher.com/8.2/pusher.min.js"></script>
<script>
const status = document.getElementById('status');

const pusher = new Pusher('b253bacbb615d39f2956', {
    cluster: 'eu',
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: { 'Authorization': 'Bearer ' + getToken() }
    }
});

const channel = pusher.subscribe('private-admin.notifications');
channel.bind('.admin.logged.in', function(data) {
    status.innerHTML = 'Received: ' + JSON.stringify(data);
});

function getToken() {
    const match = document.cookie.match(/token=([^;]+)/);
    return match ? match[1] : '';
}

function testBroadcast() {
    fetch('/test-pusher')
        .then(r => r.json())
        .then(d => status.innerHTML = 'Broadcast sent: ' + JSON.stringify(d));
}
</script>
</body>
</html>
