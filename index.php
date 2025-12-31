<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>V6Direct</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @keyframes pulse-slow {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .pulse-slow {
            animation: pulse-slow 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        .filter-btn {
            color: #9ca3af;
        }
        .filter-btn.active {
            background-color: #3b82f6;
            color: white;
        }
        .filter-btn:not(.active):hover {
            background-color: #4b5563;
        }
    </style>
</head>
<body class="bg-gray-900 text-gray-100">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="bg-gray-800 border-b border-gray-700">
            <div class="container mx-auto px-4 py-6">
                <div class="flex justify-between items-center">
                    <div>
                        <h1 class="text-3xl font-bold text-white">V6Direct Monitoring</h1>
                        <p class="text-gray-400 mt-1">Network Point of Presence Monitoring</p>
                    </div>
                    <div class="flex items-center gap-4">
                        <!-- IP Version Filter -->
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-gray-400">Show:</span>
                            <div class="flex bg-gray-700 rounded-lg p-1">
                                <button id="filterAll" class="filter-btn active px-3 py-1 rounded text-sm transition">All</button>
                                <button id="filterV4" class="filter-btn px-3 py-1 rounded text-sm transition">IPv4</button>
                                <button id="filterV6" class="filter-btn px-3 py-1 rounded text-sm transition">IPv6</button>
                            </div>
                        </div>
                        <span class="text-sm text-gray-400">Auto-refresh:</span>
                        <button id="refreshBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                            Refresh All
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Alert Banner -->
        <div id="alertBanner" class="hidden bg-red-900/50 border-b border-red-700">
            <div class="container mx-auto px-4 py-3">
                <div class="flex items-center gap-3">
                    <svg class="w-5 h-5 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                    <div id="alertContent" class="flex-1 text-red-200 text-sm"></div>
                    <button onclick="document.getElementById('alertBanner').classList.add('hidden')" class="text-red-400 hover:text-red-300">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <main class="container mx-auto px-4 py-8">
            <div id="popsContainer" class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
                <!-- PoP cards will be dynamically loaded here -->
            </div>
        </main>
    </div>

    <script>
        // Configuration
        const API_BASE_URL = 'api.php';
        const REFRESH_INTERVAL = 30000; // 30 seconds

        let autoRefreshInterval;
        let currentFilter = 'all'; // 'all', 'v4', 'v6'
        let cachedPopData = {}; // Cache for filtering without re-fetching
        let previousStates = {}; // Track previous session states for alerts

        // Initialize dashboard
        async function init() {
            setupFilterButtons();
            await loadPops();
            startAutoRefresh();
        }

        // Setup filter buttons
        function setupFilterButtons() {
            document.getElementById('filterAll').addEventListener('click', () => setFilter('all'));
            document.getElementById('filterV4').addEventListener('click', () => setFilter('v4'));
            document.getElementById('filterV6').addEventListener('click', () => setFilter('v6'));
        }

        function setFilter(filter) {
            currentFilter = filter;
            
            // Update active button
            document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
            if (filter === 'all') document.getElementById('filterAll').classList.add('active');
            if (filter === 'v4') document.getElementById('filterV4').classList.add('active');
            if (filter === 'v6') document.getElementById('filterV6').classList.add('active');
            
            // Re-render all PoPs with filter
            Object.keys(cachedPopData).forEach(popId => {
                const card = document.getElementById(`pop-${popId}`);
                if (card && cachedPopData[popId]) {
                    updatePopCard(card, cachedPopData[popId]);
                }
            });
        }

        // Load all PoPs
        async function loadPops() {
            try {
                const response = await fetch(`${API_BASE_URL}?action=get_pops`);
                const result = await response.json();

                if (result.success) {
                    const container = document.getElementById('popsContainer');
                    container.innerHTML = '';

                    for (const pop of result.data) {
                        await loadPopStatus(pop);
                    }
                }
            } catch (error) {
                console.error('Error loading PoPs:', error);
            }
        }

        // Load status for a specific PoP
        async function loadPopStatus(pop) {
            const container = document.getElementById('popsContainer');
            
            // Create or update PoP card
            let popCard = document.getElementById(`pop-${pop.id}`);
            if (!popCard) {
                popCard = createPopCard(pop);
                container.appendChild(popCard);
            }

            try {
                // First update from exporter
                await fetch(`${API_BASE_URL}?action=update_from_exporter&pop_id=${pop.id}`);
                
                // Then get current status
                const response = await fetch(`${API_BASE_URL}?action=get_pop_status&pop_id=${pop.id}`);
                const result = await response.json();

                if (result.success) {
                    cachedPopData[pop.id] = result; // Cache the data
                    updatePopCard(popCard, result);
                    checkForAlerts(result, pop);
                }
            } catch (error) {
                console.error(`Error loading status for ${pop.name}:`, error);
                showError(popCard, 'Failed to load data');
            }
        }

        // Create PoP card HTML structure
        function createPopCard(pop) {
            const card = document.createElement('div');
            card.id = `pop-${pop.id}`;
            card.className = 'bg-gray-800 rounded-lg border border-gray-700 overflow-hidden';
            card.innerHTML = `
                <div class="bg-gradient-to-r from-blue-600 to-blue-700 p-4">
                    <h2 class="text-xl font-bold text-white">${pop.name}</h2>
                    <p class="text-blue-100 text-sm">${pop.location}</p>
                </div>
                <div class="p-6">
                    <div class="flex justify-center items-center h-32">
                        <div class="pulse-slow">
                            <svg class="w-12 h-12 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            `;
            return card;
        }

        // Update PoP card with data
        function updatePopCard(card, data) {
            const { stats, sessions } = data;

            // Filter sessions based on current filter
            let filteredSessions = sessions;
            if (currentFilter === 'v4') {
                filteredSessions = sessions.filter(s => s.peer_name.includes('_v4'));
            } else if (currentFilter === 'v6') {
                filteredSessions = sessions.filter(s => s.peer_name.includes('_v6'));
            }

            // Organize stats by type (recalculate from filtered sessions)
            const statsByType = {
                upstream: { total: 0, online: 0, offline: 0, prefixes_in: 0, prefixes_out: 0 },
                downstream: { total: 0, online: 0, offline: 0, prefixes_in: 0, prefixes_out: 0 },
                peering: { total: 0, online: 0, offline: 0, prefixes_in: 0, prefixes_out: 0 }
            };

            filteredSessions.forEach(session => {
                const type = session.session_type;
                statsByType[type].total++;
                if (session.last_status === 'online') statsByType[type].online++;
                if (session.last_status === 'offline') statsByType[type].offline++;
                statsByType[type].prefixes_in += parseInt(session.prefixes_imported || 0);
                statsByType[type].prefixes_out += parseInt(session.prefixes_exported || 0);
            });

            // Calculate totals
            const totalPrefixesIn = Object.values(statsByType).reduce((sum, s) => sum + s.prefixes_in, 0);
            const totalPrefixesOut = Object.values(statsByType).reduce((sum, s) => sum + s.prefixes_out, 0);

            const content = `
                <div class="p-6 space-y-6">
                    <!-- Prefix Statistics -->
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gray-700 p-4 rounded-lg">
                            <div class="text-gray-400 text-sm mb-1">Prefixes In</div>
                            <div class="text-2xl font-bold text-green-400">${totalPrefixesIn.toLocaleString()}</div>
                        </div>
                        <div class="bg-gray-700 p-4 rounded-lg">
                            <div class="text-gray-400 text-sm mb-1">Prefixes Out</div>
                            <div class="text-2xl font-bold text-blue-400">${totalPrefixesOut.toLocaleString()}</div>
                        </div>
                    </div>

                    <!-- Upstreams -->
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-semibold text-gray-300">Upstreams</h3>
                            <div class="flex gap-2 text-sm">
                                <span class="text-green-400">${statsByType.upstream.online} online</span>
                                <span class="text-gray-500">/</span>
                                <span class="text-red-400">${statsByType.upstream.offline} offline</span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            ${renderSessions(filteredSessions.filter(s => s.session_type === 'upstream'))}
                        </div>
                    </div>

                    <!-- Downstreams -->
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-semibold text-gray-300">Downstreams</h3>
                            <div class="flex gap-2 text-sm">
                                <span class="text-green-400">${statsByType.downstream.online} online</span>
                                <span class="text-gray-500">/</span>
                                <span class="text-red-400">${statsByType.downstream.offline} offline</span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            ${renderSessions(filteredSessions.filter(s => s.session_type === 'downstream'))}
                        </div>
                    </div>

                    <!-- Peering Exchanges -->
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-semibold text-gray-300">Peering Exchanges</h3>
                            <div class="flex gap-2 text-sm">
                                <span class="text-green-400">${statsByType.peering.online} online</span>
                                <span class="text-gray-500">/</span>
                                <span class="text-red-400">${statsByType.peering.offline} offline</span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            ${renderSessions(filteredSessions.filter(s => s.session_type === 'peering'))}
                        </div>
                    </div>

                    <!-- Last Update -->
                    <div class="text-xs text-gray-500 text-center pt-4 border-t border-gray-700">
                        Last updated: ${new Date().toLocaleTimeString('de-DE')}
                    </div>
                </div>
            `;

            const contentDiv = card.querySelector('.p-6') || card.lastElementChild;
            contentDiv.innerHTML = content;
        }

        // Render individual sessions
        function renderSessions(sessions) {
            if (sessions.length === 0) {
                return '<div class="text-gray-500 text-sm italic">No sessions configured</div>';
            }

            return sessions.map(session => {
                const statusColor = session.last_status === 'online' ? 'bg-green-500' : 'bg-red-500';
                
                return `
                    <div class="bg-gray-700 p-3 rounded-lg flex items-center justify-between hover:bg-gray-600 transition cursor-pointer"
                         onclick="openChartModal(${session.id}, '${session.peer_name}')">
                        <div class="flex items-center gap-3">
                            <div class="w-2 h-2 rounded-full ${statusColor}"></div>
                            <div>
                                <div class="font-medium text-sm">${session.peer_name}</div>
                                <div class="text-xs text-gray-400">AS${session.peer_asn}</div>
                            </div>
                        </div>
                        <div class="text-right text-xs">
                            <div class="text-green-400">↓ ${(session.prefixes_imported || 0).toLocaleString()}</div>
                            <div class="text-blue-400">↑ ${(session.prefixes_exported || 0).toLocaleString()}</div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Show error message
        function showError(card, message) {
            const contentDiv = card.querySelector('.p-6') || card.lastElementChild;
            contentDiv.innerHTML = `
                <div class="flex items-center justify-center h-32">
                    <div class="text-center">
                        <svg class="w-12 h-12 text-red-500 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <p class="text-red-400 text-sm">${message}</p>
                    </div>
                </div>
            `;
        }

        // Start auto-refresh
        function startAutoRefresh() {
            autoRefreshInterval = setInterval(loadPops, REFRESH_INTERVAL);
        }

        // Manual refresh button
        document.getElementById('refreshBtn').addEventListener('click', () => {
            loadPops();
        });

        // Initialize on page load
        init();

        // Check for alerts (client-side display only, Discord is server-side)
        function checkForAlerts(data, pop) {
            const { sessions } = data;
            const popKey = `pop_${pop.id}`;
            
            if (!previousStates[popKey]) {
                // First load - store initial states
                previousStates[popKey] = {};
                sessions.forEach(session => {
                    previousStates[popKey][session.id] = session.last_status;
                });
            }
            
            // Check for status changes (for new alerts)
            const newAlerts = [];
            sessions.forEach(session => {
                const previousStatus = previousStates[popKey][session.id];
                const currentStatus = session.last_status;
                
                if (previousStatus && previousStatus !== currentStatus) {
                    if (currentStatus === 'offline') {
                        newAlerts.push(`🔴 ${pop.name}: ${session.peer_name} went OFFLINE`);
                    } else if (currentStatus === 'online') {
                        newAlerts.push(`🟢 ${pop.name}: ${session.peer_name} is back ONLINE`);
                    }
                }
                
                // Update state
                previousStates[popKey][session.id] = currentStatus;
            });
            
            // Show new alerts temporarily (30 sec)
            if (newAlerts.length > 0) {
                showTemporaryAlert(newAlerts);
            }
            
            // Always update persistent banner with current offline sessions
            updatePersistentAlerts();
        }

        function showTemporaryAlert(alerts) {
            const banner = document.getElementById('alertBanner');
            const content = document.getElementById('alertContent');
            
            content.innerHTML = alerts.join(' • ');
            banner.classList.remove('hidden');
            
            // Auto-hide after 30 seconds
            setTimeout(() => {
                updatePersistentAlerts();
            }, 30000);
        }

        function updatePersistentAlerts() {
            const banner = document.getElementById('alertBanner');
            const content = document.getElementById('alertContent');
            
            // Collect all currently offline sessions
            const offlineSessions = [];
            
            Object.keys(cachedPopData).forEach(popId => {
                const data = cachedPopData[popId];
                if (data && data.sessions) {
                    data.sessions.forEach(session => {
                        if (session.last_status === 'offline') {
                            offlineSessions.push(`${data.pop.name}: ${session.peer_name}`);
                        }
                    });
                }
            });
            
            if (offlineSessions.length > 0) {
                content.innerHTML = `🔴 ${offlineSessions.length} session(s) offline: ${offlineSessions.join(' • ')}`;
                banner.classList.remove('hidden');
            } else {
                banner.classList.add('hidden');
            }
        }
        let chartInstance = null;

async function openChartModal(sessionId, peerName) {
    document.getElementById('chartModal').classList.remove('hidden');
    document.getElementById('chartModal').classList.add('flex');
    document.getElementById('chartTitle').innerText = peerName;

    const res = await fetch(`${API_BASE_URL}?action=get_history&session_id=${sessionId}&hours=24`);
    const result = await res.json();

    if (!result.success) return;

    const labels = result.data.map(d =>
        new Date(d.recorded_at).toLocaleTimeString('de-DE')
    );

    const prefixesIn = result.data.map(d => d.prefixes_imported);
    const prefixesOut = result.data.map(d => d.prefixes_exported);

    const ctx = document.getElementById('sessionChart').getContext('2d');

    if (chartInstance) chartInstance.destroy();

    chartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Prefixes In',
                    data: prefixesIn,
                    borderWidth: 2,
                    tension: 0.3
                },
                {
                    label: 'Prefixes Out',
                    data: prefixesOut,
                    borderWidth: 2,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { labels: { color: '#e5e7eb' } }
            },
            scales: {
                x: { ticks: { color: '#9ca3af' } },
                y: { ticks: { color: '#9ca3af' } }
            }
        }
    });
}

function closeChartModal() {
    document.getElementById('chartModal').classList.add('hidden');
    document.getElementById('chartModal').classList.remove('flex');
}

    </script>
    <!-- Chart Modal -->
<div id="chartModal" class="fixed inset-0 bg-black/70 hidden items-center justify-center z-50">
    <div class="bg-gray-800 rounded-lg w-full max-w-3xl p-6 relative">
        <button onclick="closeChartModal()" class="absolute top-3 right-3 text-gray-400 hover:text-white">✕</button>
        <h2 id="chartTitle" class="text-xl font-bold mb-4"></h2>
        <canvas id="sessionChart" height="120"></canvas>
    </div>
</div>

</body>
</html>
