<x-mcp::app :title="$title">
    <x-slot:head>
        <style>
            :root {
                color-scheme: light dark;
                --background: light-dark(#ffffff, #141414);
                --surface: light-dark(#f7f7f3, #1e1e1a);
                --surface-strong: light-dark(#ffffff, #25251f);
                --text: light-dark(#20221d, #f5f5ef);
                --muted: light-dark(#676b5e, #b7b7aa);
                --border: light-dark(#dedfd6, #3c3d35);
                --accent: light-dark(#0f766e, #5eead4);
                --accent-strong: light-dark(#115e59, #2dd4bf);
                --danger: light-dark(#b42318, #f97066);
                --warning: light-dark(#9a5b13, #fdb022);
                --success: light-dark(#067647, #32d583);
                --info: light-dark(#175cd3, #84caff);
                --radius: 8px;
                --font: var(--font-sans, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif);
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                background: var(--background);
                color: var(--text);
                font-family: var(--font);
            }

            button,
            input,
            select {
                font: inherit;
            }

            #family-fund-review {
                min-width: 320px;
                padding: 16px;
            }

            .topbar {
                align-items: end;
                display: grid;
                gap: 12px;
                grid-template-columns: minmax(180px, 1fr) repeat(3, minmax(112px, max-content));
                margin-bottom: 14px;
            }

            h1 {
                font-size: 20px;
                line-height: 1.2;
                margin: 0 0 4px;
            }

            .period {
                color: var(--muted);
                font-size: 13px;
            }

            .field {
                display: grid;
                gap: 5px;
            }

            label {
                color: var(--muted);
                font-size: 12px;
                font-weight: 650;
            }

            select {
                background: var(--surface-strong);
                border: 1px solid var(--border);
                border-radius: var(--radius);
                color: var(--text);
                min-height: 36px;
                padding: 6px 10px;
            }

            .button {
                align-items: center;
                background: var(--surface-strong);
                border: 1px solid var(--border);
                border-radius: var(--radius);
                color: var(--text);
                cursor: pointer;
                display: inline-flex;
                gap: 8px;
                justify-content: center;
                min-height: 36px;
                padding: 7px 12px;
                white-space: nowrap;
            }

            .button.primary {
                background: var(--accent);
                border-color: var(--accent);
                color: light-dark(#ffffff, #082f2c);
                font-weight: 700;
            }

            .button:disabled {
                cursor: not-allowed;
                opacity: .55;
            }

            .metrics {
                display: grid;
                gap: 10px;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                margin-bottom: 14px;
            }

            .metric,
            .panel {
                background: var(--surface);
                border: 1px solid var(--border);
                border-radius: var(--radius);
            }

            .metric {
                min-height: 84px;
                padding: 12px;
            }

            .metric span {
                color: var(--muted);
                display: block;
                font-size: 12px;
                font-weight: 650;
                margin-bottom: 10px;
            }

            .metric strong {
                display: block;
                font-size: 22px;
                line-height: 1.1;
                overflow-wrap: anywhere;
            }

            .panel {
                overflow: hidden;
            }

            .panel-head {
                align-items: center;
                border-bottom: 1px solid var(--border);
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                justify-content: space-between;
                padding: 12px;
            }

            .channel-group {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }

            .channel {
                align-items: center;
                color: var(--muted);
                display: inline-flex;
                font-size: 13px;
                gap: 6px;
            }

            table {
                border-collapse: collapse;
                width: 100%;
            }

            th,
            td {
                border-bottom: 1px solid var(--border);
                padding: 10px 12px;
                text-align: left;
                vertical-align: middle;
            }

            th {
                color: var(--muted);
                font-size: 12px;
                font-weight: 750;
            }

            td {
                font-size: 14px;
            }

            tr:last-child td {
                border-bottom: 0;
            }

            .amount {
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }

            .badge {
                border: 1px solid currentColor;
                border-radius: 999px;
                display: inline-flex;
                font-size: 12px;
                font-weight: 750;
                line-height: 1;
                padding: 5px 8px;
                white-space: nowrap;
            }

            .paid {
                color: var(--success);
            }

            .partial {
                color: var(--info);
            }

            .unpaid {
                color: var(--warning);
            }

            .overdue {
                color: var(--danger);
            }

            .channels {
                color: var(--muted);
                font-size: 12px;
            }

            .message {
                border-top: 1px solid var(--border);
                color: var(--muted);
                font-size: 13px;
                padding: 12px;
            }

            .message.error {
                color: var(--danger);
            }

            .message.success {
                color: var(--success);
            }

            .empty {
                color: var(--muted);
                padding: 22px 12px;
                text-align: center;
            }

            @media (max-width: 760px) {
                #family-fund-review {
                    padding: 12px;
                }

                .topbar,
                .metrics {
                    grid-template-columns: 1fr;
                }

                .table-wrap {
                    overflow-x: auto;
                }

                table {
                    min-width: 760px;
                }
            }
        </style>

        <script type="module">
        createMcpApp(async (app) => {
            const root = document.getElementById('family-fund-review');
            const state = {
                data: null,
                selected: new Set(),
                preview: null,
            };

            const formatMoney = (amount) => {
                const currency = state.data?.family?.currency ?? '';
                return `${currency}${Number(amount ?? 0).toLocaleString()}`;
            };

            const toolJson = async (name, args = {}) => {
                const result = await app.callServerTool(name, args);

                if (result.isError) {
                    throw new Error(result.content[0]?.text ?? 'The MCP tool returned an error.');
                }

                return JSON.parse(result.content[0]?.text ?? '{}');
            };

            const currentArgs = () => ({
                year: Number(root.querySelector('[data-year]').value),
                month: Number(root.querySelector('[data-month]').value),
                status: root.querySelector('[data-status]').value,
            });

            const selectedChannels = () => [...root.querySelectorAll('[data-channel]:checked')]
                .map((input) => input.value);

            const escapeHtml = (value) => String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');

            const setMessage = (message, type = '') => {
                const el = root.querySelector('[data-message]');
                el.textContent = message;
                el.className = `message ${type}`.trim();
            };

            const renderMetrics = () => {
                const summary = state.data.summary;
                root.querySelector('[data-period]').textContent = `${state.data.family.name ?? 'Family'} - ${state.data.family.period}`;
                root.querySelector('[data-expected]').textContent = formatMoney(summary.total_expected);
                root.querySelector('[data-collected]').textContent = formatMoney(summary.total_collected);
                root.querySelector('[data-outstanding]').textContent = formatMoney(summary.total_outstanding);
                root.querySelector('[data-rate]').textContent = `${summary.collection_rate}%`;
            };

            const renderRows = () => {
                const tbody = root.querySelector('tbody');
                tbody.innerHTML = '';

                if (state.data.members.length === 0) {
                    root.querySelector('[data-empty]').hidden = false;
                    return;
                }

                root.querySelector('[data-empty]').hidden = true;

                for (const member of state.data.members) {
                    const checked = member.contribution_id && state.selected.has(member.contribution_id);
                    const disabled = !member.reminder_eligible;
                    const row = document.createElement('tr');

                    row.innerHTML = `
                        <td>
                            <input type="checkbox" data-select value="${member.contribution_id ?? ''}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}>
                        </td>
                        <td>
                            <strong>${escapeHtml(member.name)}</strong>
                            <div class="channels">${escapeHtml(member.category_label)}</div>
                        </td>
                        <td class="amount">${formatMoney(member.expected_amount)}</td>
                        <td class="amount">${formatMoney(member.paid_amount)}</td>
                        <td class="amount">${formatMoney(member.balance)}</td>
                        <td><span class="badge ${escapeHtml(member.status)}">${escapeHtml(member.status_label)}</span></td>
                        <td class="channels">${escapeHtml(member.reminder_channels.length ? member.reminder_channels.join(', ') : member.reminder_ineligible_reason ?? '')}</td>
                    `;

                    tbody.appendChild(row);
                }

                root.querySelectorAll('[data-select]').forEach((input) => {
                    input.addEventListener('change', () => {
                        const id = Number(input.value);

                        if (input.checked) {
                            state.selected.add(id);
                        } else {
                            state.selected.delete(id);
                        }

                        state.preview = null;
                        renderActions();
                    });
                });
            };

            const renderActions = () => {
                root.querySelector('[data-preview]').disabled = state.selected.size === 0 || selectedChannels().length === 0;
                root.querySelector('[data-send]').disabled = !state.preview || state.preview.valid_count === 0;
                root.querySelector('[data-selected-count]').textContent = `${state.selected.size} selected`;
            };

            const load = async () => {
                setMessage('Loading review...');
                state.preview = null;

                try {
                    state.data = await toolJson('get-family-fund-review-data', currentArgs());
                    state.selected = new Set([...state.selected].filter((id) => state.data.members.some((member) => member.contribution_id === id && member.reminder_eligible)));
                    renderMetrics();
                    renderRows();
                    renderActions();
                    setMessage(`${state.data.summary.reminder_eligible_count} reminder-ready contribution(s).`);
                    await app.updateModelContext({
                        currentView: 'family-fund-review',
                        period: state.data.family.period,
                        outstanding: state.data.summary.total_outstanding,
                        reminderEligibleCount: state.data.summary.reminder_eligible_count,
                    });
                } catch (error) {
                    setMessage(error.message, 'error');
                }
            };

            const preview = async () => {
                setMessage('Preparing reminder preview...');

                try {
                    state.preview = await toolJson('send-family-fund-review-reminders', {
                        contribution_ids: [...state.selected],
                        channels: selectedChannels(),
                        confirmed: false,
                    });
                    renderActions();
                    setMessage(state.preview.message);
                } catch (error) {
                    setMessage(error.message, 'error');
                }
            };

            const send = async () => {
                setMessage('Sending reminders...');

                try {
                    const result = await toolJson('send-family-fund-review-reminders', {
                        contribution_ids: [...state.selected],
                        channels: selectedChannels(),
                        confirmed: true,
                    });
                    state.preview = null;
                    state.selected.clear();
                    setMessage(result.message, result.status === 'success' ? 'success' : '');
                    await app.updateModelContext({
                        currentView: 'family-fund-review',
                        lastReminderSend: result.message,
                    });
                    await load();
                } catch (error) {
                    setMessage(error.message, 'error');
                }
            };

            const initialiseControls = () => {
                const now = new Date();
                root.querySelector('[data-year]').value = String(now.getFullYear());
                root.querySelector('[data-month]').value = String(now.getMonth() + 1);

                root.querySelector('[data-refresh]').addEventListener('click', load);
                root.querySelector('[data-preview]').addEventListener('click', preview);
                root.querySelector('[data-send]').addEventListener('click', send);
                root.querySelector('[data-status]').addEventListener('change', load);
                root.querySelector('[data-year]').addEventListener('change', load);
                root.querySelector('[data-month]').addEventListener('change', load);
                root.querySelectorAll('[data-channel]').forEach((input) => input.addEventListener('change', () => {
                    state.preview = null;
                    renderActions();
                }));
            };

            initialiseControls();
            app.autoResize();
            await load();
        });
        </script>
    </x-slot:head>

    <div id="family-fund-review">
        <div class="topbar">
            <div>
                <h1>Family Fund Review</h1>
                <div class="period" data-period>Monthly contribution review</div>
            </div>

            <div class="field">
                <label for="review-year">Year</label>
                <select id="review-year" data-year>
                    @for ($year = now()->year - 5; $year <= now()->year + 1; $year++)
                        <option value="{{ $year }}">{{ $year }}</option>
                    @endfor
                </select>
            </div>

            <div class="field">
                <label for="review-month">Month</label>
                <select id="review-month" data-month>
                    @foreach (range(1, 12) as $month)
                        <option value="{{ $month }}">{{ \Carbon\CarbonImmutable::create(2024, $month, 1)->format('F') }}</option>
                    @endforeach
                </select>
            </div>

            <button type="button" class="button" data-refresh>Refresh</button>
        </div>

        <div class="metrics">
            <div class="metric"><span>Expected</span><strong data-expected>0</strong></div>
            <div class="metric"><span>Collected</span><strong data-collected>0</strong></div>
            <div class="metric"><span>Outstanding</span><strong data-outstanding>0</strong></div>
            <div class="metric"><span>Collection Rate</span><strong data-rate>0%</strong></div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div class="field">
                    <label for="review-status">Status</label>
                    <select id="review-status" data-status>
                        <option value="all">All</option>
                        <option value="unpaid">Unpaid</option>
                        <option value="partial">Partial</option>
                        <option value="overdue">Overdue</option>
                        <option value="paid">Paid</option>
                    </select>
                </div>

                <div class="channel-group">
                    <label class="channel"><input type="checkbox" value="mail" data-channel checked> Email</label>
                    <label class="channel"><input type="checkbox" value="whatsapp" data-channel> WhatsApp</label>
                    <label class="channel"><input type="checkbox" value="webpush" data-channel> Web Push</label>
                </div>

                <span class="period" data-selected-count>0 selected</span>
                <button type="button" class="button" data-preview disabled>Preview</button>
                <button type="button" class="button primary" data-send disabled>Send</button>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Member</th>
                            <th>Expected</th>
                            <th>Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Channels</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="empty" data-empty hidden>No matching contributions.</div>
            <div class="message" data-message>Loading review...</div>
        </div>
    </div>
</x-mcp::app>
