/**
 * Real Pusher E2E Verification — All Six Import/Export Operations
 *
 * L1 = Laravel event created
 * L2 = Laravel broadcaster invoked
 * L3 = Real Pusher service accepted the event
 * L4 = Real client actually received the event ← MANDATORY
 *
 * This test proves L4 for all six operations by:
 * 1. Connecting a real Pusher client to the real service
 * 2. Subscribing to private-users.{userId}
 * 3. Executing each operation through the real API
 * 4. Capturing all lifecycle events (queued/progress/terminal)
 * 5. Reconciling events with DB state
 */

const Pusher = require('pusher-js');
const axios = require('axios');
const fs = require('fs');
const path = require('path');
const FormData = require('form-data');

// Configuration from .env
const CONFIG = {
    apiBaseUrl: process.env.API_BASE_URL || 'http://localhost:80/api/v1',
    pusherKey: process.env.PUSHER_APP_KEY || 'b253bacbb615d39f2956',
    pusherCluster: process.env.PUSHER_APP_CLUSTER || 'eu',
    bearerToken: process.env.TEST_BEARER_TOKEN || '',
    userId: parseInt(process.env.TEST_USER_ID || '1'),
    testDataDir: path.join(__dirname, '..', 'storage', 'pusher-test'),
};

// Event catalog
const EXPECTED_EVENTS = {
    'product-import': ['product.import.queued', 'product.import.progress', 'product.import.completed'],
    'category-import': ['category.import.queued', 'category.import.progress', 'category.import.completed'],
    'brand-import': ['brand.import.queued', 'brand.import.progress', 'brand.import.completed'],
    'product-export': ['product.export.queued', 'product.export.progress', 'product.export.completed'],
    'category-export': ['category.export.queued', 'category.export.progress', 'category.export.completed'],
    'brand-export': ['brand.export.queued', 'brand.export.progress', 'brand.export.completed'],
};

const TERMINAL_STATES = ['completed', 'completed_with_errors', 'failed', 'cancelled'];

class PusherE2ETest {
    constructor() {
        this.pusher = null;
        this.channel = null;
        this.receivedEvents = new Map(); // operation_id → [events]
        this.connectionState = 'disconnected';
        this.subscriptionState = 'unsubscribed';
        this.results = [];
    }

    log(level, message, data = {}) {
        const timestamp = new Date().toISOString();
        console.log(`[${timestamp}] [${level.toUpperCase()}] ${message}`, data);
    }

    async connect() {
        return new Promise((resolve, reject) => {
            this.log('info', 'Initializing Pusher client', {
                key: CONFIG.pusherKey,
                cluster: CONFIG.pusherCluster,
            });

            this.pusher = new Pusher(CONFIG.pusherKey, {
                cluster: CONFIG.pusherCluster,
                forceTLS: true,
                authEndpoint: `${CONFIG.apiBaseUrl.replace('/api/v1', '')}/broadcasting/auth`,
                auth: {
                    headers: {
                        'Authorization': `Bearer ${CONFIG.bearerToken}`,
                        'Accept': 'application/json',
                    },
                },
            });

            this.pusher.connection.bind('connecting', () => {
                this.connectionState = 'connecting';
                this.log('info', 'Pusher connection: connecting');
            });

            this.pusher.connection.bind('connected', () => {
                this.connectionState = 'connected';
                this.log('info', 'Pusher connection: CONNECTED');
                resolve();
            });

            this.pusher.connection.bind('disconnected', () => {
                this.connectionState = 'disconnected';
                this.log('warn', 'Pusher connection: disconnected');
            });

            this.pusher.connection.bind('failed', () => {
                this.connectionState = 'failed';
                this.log('error', 'Pusher connection: FAILED');
                reject(new Error('Pusher connection failed'));
            });

            this.pusher.connection.bind('error', (err) => {
                this.log('error', 'Pusher connection error', err);
            });

            // Timeout
            setTimeout(() => {
                if (this.connectionState !== 'connected') {
                    reject(new Error('Pusher connection timeout'));
                }
            }, 15000);
        });
    }

    async subscribe() {
        return new Promise((resolve, reject) => {
            const channelName = `private-users.${CONFIG.userId}`;
            this.log('info', `Subscribing to channel: ${channelName}`);

            this.channel = this.pusher.subscribe(channelName);

            this.channel.bind('pusher:subscription_succeeded', () => {
                this.subscriptionState = 'subscribed';
                this.log('info', `Channel subscription: SUCCEEDED`);
                resolve();
            });

            this.channel.bind('pusher:subscription_error', (err) => {
                this.subscriptionState = 'failed';
                this.log('error', 'Channel subscription: FAILED', err);
                reject(new Error(`Subscription failed: ${JSON.stringify(err)}`));
            });

            // Listen to all FileOperation events
            Object.values(EXPECTED_EVENTS).flat().forEach(eventName => {
                // Echo/Pusher uses leading dot for broadcastAs events
                this.channel.bind(`.${eventName}`, (data) => {
                    this.handleEvent(eventName, data);
                });
            });

            // Timeout
            setTimeout(() => {
                if (this.subscriptionState !== 'subscribed') {
                    reject(new Error('Channel subscription timeout'));
                }
            }, 10000);
        });
    }

    handleEvent(eventName, data) {
        const operationId = data.operation_id || data.id;
        this.log('info', `EVENT RECEIVED: ${eventName}`, {
            operation_id: operationId,
            state: data.state || data.status,
            progress: data.progress,
            has_errors: data.has_errors,
            download_available: data.download_available,
        });

        if (!this.receivedEvents.has(operationId)) {
            this.receivedEvents.set(operationId, []);
        }

        this.receivedEvents.get(operationId).push({
            event: eventName,
            timestamp: new Date().toISOString(),
            data: data,
        });
    }

    async executeOperation(kind, endpoint, method = 'POST', payload = null) {
        this.log('info', `Starting operation: ${kind}`, { endpoint, method });

        try {
            const config = {
                method,
                url: `${CONFIG.apiBaseUrl}/${endpoint}`,
                headers: {
                    'Authorization': `Bearer ${CONFIG.bearerToken}`,
                    'Accept': 'application/json',
                },
            };

            if (kind.includes('import')) {
                // Import: multipart/form-data with file
                const formData = new FormData();
                const sampleFile = this.getSampleFile(kind);
                formData.append('file', fs.createReadStream(sampleFile));

                config.data = formData;
                config.headers = {
                    ...config.headers,
                    ...formData.getHeaders(),
                    'Idempotency-Key': `test-${Date.now()}-${Math.random()}`,
                };
            } else if (payload) {
                // Export with filters
                config.data = payload;
                config.headers['Content-Type'] = 'application/json';
            }

            const response = await axios(config);

            if (response.status !== 202) {
                throw new Error(`Expected 202, got ${response.status}`);
            }

            const operationId = response.data.data.import_id || response.data.data.export_id || response.data.data.id;

            this.log('info', `Operation started: ${kind}`, {
                operation_id: operationId,
                status: response.data.data.status,
            });

            return { operationId, response: response.data };

        } catch (error) {
            this.log('error', `Failed to start operation: ${kind}`, {
                error: error.message,
                response: error.response?.data,
            });
            throw error;
        }
    }

    getSampleFile(kind) {
        const fileMap = {
            'product-import': path.join(CONFIG.testDataDir, 'product-sample.xlsx'),
            'category-import': path.join(CONFIG.testDataDir, 'category-sample.xlsx'),
            'brand-import': path.join(CONFIG.testDataDir, 'brand-sample.xlsx'),
        };

        const filePath = fileMap[kind];
        if (!fs.existsSync(filePath)) {
            throw new Error(`Sample file not found: ${filePath}`);
        }
        return filePath;
    }

    async waitForTerminalEvent(operationId, kind, timeoutMs = 120000) {
        const startTime = Date.now();

        return new Promise((resolve, reject) => {
            const checkInterval = setInterval(() => {
                const events = this.receivedEvents.get(operationId) || [];
                const terminalEvent = events.find(e =>
                    e.event.includes('completed') || e.event.includes('failed') || e.event.includes('cancelled')
                );

                if (terminalEvent) {
                    clearInterval(checkInterval);
                    this.log('info', `Terminal event received for operation ${operationId}`, {
                        event: terminalEvent.event,
                        state: terminalEvent.data.state,
                    });
                    resolve(terminalEvent);
                } else if (Date.now() - startTime > timeoutMs) {
                    clearInterval(checkInterval);
                    reject(new Error(`Timeout waiting for terminal event (operation ${operationId})`));
                }
            }, 500);
        });
    }

    async fetchStatus(kind, operationId) {
        const endpointMap = {
            'product-import': `products/import/${operationId}`,
            'category-import': `categories/import/${operationId}`,
            'brand-import': `brands/import/${operationId}`,
            'product-export': `products/export/${operationId}`,
            'category-export': `categories/export/${operationId}`,
            'brand-export': `brands/export/${operationId}`,
        };

        const response = await axios.get(`${CONFIG.apiBaseUrl}/${endpointMap[kind]}`, {
            headers: {
                'Authorization': `Bearer ${CONFIG.bearerToken}`,
                'Accept': 'application/json',
            },
        });

        return response.data.data;
    }

    async testOperation(kind, endpoint, method = 'POST', payload = null) {
        this.log('info', `========== TESTING: ${kind.toUpperCase()} ==========`);

        const result = {
            operation: kind,
            apiStart: false,
            operationId: null,
            pusherConnected: this.connectionState === 'connected',
            privateChannel: this.subscriptionState === 'subscribed',
            queuedEvent: false,
            progressEvent: false,
            terminalEvent: false,
            dbMatch: false,
            events: [],
            dbState: null,
            errors: [],
        };

        try {
            // Start operation
            const { operationId } = await this.executeOperation(kind, endpoint, method, payload);
            result.operationId = operationId;
            result.apiStart = true;

            // Wait for terminal event
            await this.waitForTerminalEvent(operationId, kind);

            // Collect received events
            const events = this.receivedEvents.get(operationId) || [];
            result.events = events.map(e => e.event);
            result.queuedEvent = events.some(e => e.event.includes('queued'));
            result.progressEvent = events.some(e => e.event.includes('progress'));
            result.terminalEvent = events.some(e =>
                e.event.includes('completed') || e.event.includes('failed') || e.event.includes('cancelled')
            );

            // Fetch DB state
            await new Promise(resolve => setTimeout(resolve, 1000)); // Let DB settle
            const dbState = await this.fetchStatus(kind, operationId);
            result.dbState = dbState;

            // Reconcile event vs DB
            const terminalEvent = events.find(e =>
                e.event.includes('completed') || e.event.includes('failed') || e.event.includes('cancelled')
            );

            if (terminalEvent && dbState) {
                const eventState = terminalEvent.data.state || terminalEvent.data.status;
                const dbStatus = dbState.status;
                result.dbMatch = eventState === dbStatus;

                if (!result.dbMatch) {
                    result.errors.push(`Event state (${eventState}) != DB state (${dbStatus})`);
                }
            }

            this.log('info', `${kind} result`, result);

        } catch (error) {
            result.errors.push(error.message);
            this.log('error', `${kind} test failed`, { error: error.message });
        }

        this.results.push(result);
        return result;
    }

    async runAllTests() {
        this.log('info', '========== REAL PUSHER E2E CERTIFICATION ==========');

        // Connect and subscribe
        await this.connect();
        await this.subscribe();

        // Test all six operations
        await this.testOperation('product-import', 'products/import');
        await this.testOperation('category-import', 'categories/import');
        await this.testOperation('brand-import', 'brands/import');
        await this.testOperation('product-export', 'products/export', 'GET');
        await this.testOperation('category-export', 'categories/export', 'GET');
        await this.testOperation('brand-export', 'brands/export', 'GET');

        // Generate report
        this.generateReport();
    }

    generateReport() {
        this.log('info', '========== FINAL CERTIFICATION REPORT ==========');

        const overallPass = this.results.every(r =>
            r.apiStart && r.queuedEvent && r.progressEvent && r.terminalEvent && r.dbMatch && r.errors.length === 0
        );

        console.log('\n# REAL PUSHER + IMPORT/EXPORT CERTIFICATION\n');
        console.log(`Overall Status: ${overallPass ? 'PASS' : 'FAIL'}\n`);

        console.log('## Pusher');
        console.log(`Connection: ${this.connectionState === 'connected' ? 'PASS' : 'FAIL'}`);
        console.log(`Private Channel: ${this.subscriptionState === 'subscribed' ? 'PASS' : 'FAIL'}\n`);

        console.log('## Operations\n');
        this.results.forEach(r => {
            console.log(`${r.operation}: ${
                r.apiStart && r.queuedEvent && r.terminalEvent && r.dbMatch && r.errors.length === 0 ? 'PASS' : 'FAIL'
            }`);
            console.log(`  API Start: ${r.apiStart ? 'PASS' : 'FAIL'}`);
            console.log(`  Queued Event: ${r.queuedEvent ? 'PASS' : 'FAIL'}`);
            console.log(`  Progress Event: ${r.progressEvent ? 'PASS' : 'FAIL'}`);
            console.log(`  Terminal Event: ${r.terminalEvent ? 'PASS' : 'FAIL'}`);
            console.log(`  DB Match: ${r.dbMatch ? 'PASS' : 'FAIL'}`);
            console.log(`  Events: ${r.events.join(', ')}`);
            console.log(`  DB State: ${r.dbState?.status || 'N/A'}`);
            if (r.errors.length > 0) {
                console.log(`  Errors: ${r.errors.join('; ')}`);
            }
            console.log('');
        });

        console.log(`\n## Final Certification: ${overallPass ? 'PASS' : 'FAIL'}\n`);

        // Write detailed log
        const logPath = path.join(CONFIG.testDataDir, 'e2e-test-result.json');
        fs.writeFileSync(logPath, JSON.stringify({
            timestamp: new Date().toISOString(),
            overallPass,
            connectionState: this.connectionState,
            subscriptionState: this.subscriptionState,
            results: this.results,
        }, null, 2));

        this.log('info', `Detailed results written to: ${logPath}`);
    }

    disconnect() {
        if (this.pusher) {
            this.pusher.disconnect();
        }
    }
}

// Run test
(async () => {
    if (!CONFIG.bearerToken) {
        console.error('ERROR: TEST_BEARER_TOKEN environment variable required');
        process.exit(1);
    }

    const test = new PusherE2ETest();

    try {
        await test.runAllTests();
    } catch (error) {
        console.error('Fatal error:', error);
        process.exit(1);
    } finally {
        test.disconnect();
        process.exit(0);
    }
})();
