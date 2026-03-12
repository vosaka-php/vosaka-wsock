/**
 * Node.js Integration Test for vosaka-wsock Chat Server
 * 
 * This test uses the native WebSocket API (available in Node.js 22+)
 * to verify that multiple clients can connect and exchange messages.
 */

async function runTest() {
    const URL = 'ws://127.0.0.1:9000/chat';
    const TEST_TIMEOUT = 10000; // 10 seconds

    console.log('--- Starting WebSocket Integration Test ---');

    const client1 = new WebSocket(URL);
    const client2 = new WebSocket(URL);

    // Track state
    const state = {
        client1Welcome: null,
        client2Welcome: null,
        client2ReceivedMessage: null,
        client1Opened: false,
        client2Opened: false
    };

    // Setup handlers immediately to catch early messages
    client1.onopen = () => {
        state.client1Opened = true;
        console.log('✓ Client 1 connected');
    };
    client1.onmessage = (e) => {
        console.log(`[Client 1] Received: ${e.data}`);
        const data = JSON.parse(e.data);
        if (data.event === 'welcome') state.client1Welcome = data;
    };

    client2.onopen = () => {
        state.client2Opened = true;
        console.log('✓ Client 2 connected');
    };
    client2.onmessage = (e) => {
        console.log(`[Client 2] Received: ${e.data}`);
        const data = JSON.parse(e.data);
        if (data.event === 'welcome') state.client2Welcome = data;
        if (data.event === 'message') state.client2ReceivedMessage = data;
    };

    const waitFor = (condition, timeout = TEST_TIMEOUT) => {
        return new Promise((resolve, reject) => {
            const start = Date.now();
            const interval = setInterval(() => {
                if (condition()) {
                    clearInterval(interval);
                    resolve();
                } else if (Date.now() - start > timeout) {
                    clearInterval(interval);
                    reject(new Error('Timeout waiting for condition'));
                }
            }, 100);
        });
    };

    try {
        // 1. Wait for both clients to connect and welcome
        await waitFor(() => state.client1Opened && state.client2Opened);
        console.log('✓ Both clients opened');

        // 2. Wait for welcome messages
        await waitFor(() => state.client1Welcome && state.client2Welcome);
        console.log('✓ Welcome messages received');
        console.log(`  Client 1 ID: ${state.client1Welcome.id}`);
        console.log(`  Client 2 ID: ${state.client2Welcome.id}`);

        // 3. Client 1 sends a message
        const testText = "Hello from Node.js Test Client!";
        client1.send(JSON.stringify({
            event: 'message',
            room: 'general',
            text: testText
        }));
        console.log('✓ Client 1 sent message');

        // 4. Wait for Client 2 to receive the broadcast
        await waitFor(() => state.client2ReceivedMessage);
        
        if (state.client2ReceivedMessage.text === testText && state.client2ReceivedMessage.from === state.client1Welcome.id) {
            console.log('✓ Client 2 received correct broadcast');
        } else {
            throw new Error(`Broadcast mismatch: ${JSON.stringify(state.client2ReceivedMessage)}`);
        }

        console.log('--- TEST PASSED SUCCESSFULLY ---');
        process.exit(0);

    } catch (err) {
        console.error('--- TEST FAILED ---');
        console.error(err.message);
        process.exit(1);
    } finally {
        client1.close();
        client2.close();
    }
}

runTest();
