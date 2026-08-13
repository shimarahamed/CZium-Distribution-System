import { useState, useEffect, useCallback, useRef } from "react";
import Api from "./lib/api.js";
import { Button, Badge, Card, CardHeader, CardTitle, KpiCard, Input, Select, Textarea,
         Modal, Table, Spinner, EmptyState, ProgressBar, BarChart, Tabs,
         PageHeader, StatusBadge, SearchInput, Toast } from "./components/ui/index.jsx";

// ─── Theme hook ───────────────────────────────────────────
function useTheme() {
  const [dark, setDark] = useState(() => localStorage.getItem('czium_theme') === 'dark'
    || (!localStorage.getItem('czium_theme') && window.matchMedia('(prefers-color-scheme: dark)').matches));
  useEffect(() => {
    document.documentElement.classList.toggle('dark', dark);
    localStorage.setItem('czium_theme', dark ? 'dark' : 'light');
  }, [dark]);
  return [dark, () => setDark(d => !d)];
}

// ─── Auth hook ────────────────────────────────────────────
function useAuth() {
  const [user, setUser] = useState(Api.user);
  const [loading, setLoading] = useState(!Api.user && !!Api.token);

  useEffect(() => {
    if (!Api.token) { setLoading(false); return; }
    if (Api.user)   { setLoading(false); return; }
    Api.get('auth/me').then(u => { Api.setUser(u); setUser(u); }).catch(() => { Api.clearAuth(); }).finally(() => setLoading(false));
  }, []);

  const login  = async (email, password, tenant) => { const r = await Api.post('auth/login', { email, password, tenant }); Api.setToken(r.token); Api.setUser(r.user); setUser(r.user); return r; };
  const logout = async () => { try { await Api.post('auth/logout', {}); } catch {} Api.clearAuth(); setUser(null); };
  return { user, loading, login, logout };
}

// ─── Fetch hook ───────────────────────────────────────────
function useFetch(endpoint, query, deps = []) {
  const [data, setData]     = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError]   = useState(null);
  const load = useCallback(async () => {
    if (!endpoint) return;
    setLoading(true); setError(null);
    try { setData(await Api.get(endpoint, query)); } catch (e) { setError(e.message); }
    finally { setLoading(false); }
  }, [endpoint, JSON.stringify(query)]);
  useEffect(() => { load(); }, [load, ...deps]);
  return { data, loading, error, reload: load };
}

// ─── Currency formatter ───────────────────────────────────
const fmt  = n => `Rs. ${(+n || 0).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const fmtN = n => (+n || 0).toLocaleString();

// ═══════════════════════════════════════════════════════════
// LOGIN PAGE
// ═══════════════════════════════════════════════════════════
function LoginPage({ onLogin }) {
  const [f, setF]   = useState({ email: 'admin@metrodist.com', password: '', tenant: 'czium-dist' });
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async e => {
    e.preventDefault(); setErr(''); setBusy(true);
    try { await onLogin(f.email, f.password, f.tenant); }
    catch (e) { setErr(e.message || 'Login failed'); }
    finally { setBusy(false); }
  };

  return (
    <div className="min-h-screen bg-background flex items-center justify-center p-4">
      <div className="w-full max-w-sm">
        <div className="text-center mb-8">
          <div className="w-14 h-14 bg-primary rounded-2xl flex items-center justify-center text-2xl mx-auto mb-4 shadow-md">🌶️</div>
          <h1 className="text-2xl font-bold text-foreground">CZium Distribution</h1>
          <p className="text-sm text-muted-foreground mt-1">Spice Manufacturing & Distribution ERP</p>
        </div>
        <Card className="shadow-md">
          <form onSubmit={submit} className="space-y-4">
            <Input label="Email" type="email" value={f.email} onChange={e => setF(p => ({ ...p, email: e.target.value }))} required autoComplete="email" />
            <Input label="Password" type="password" value={f.password} onChange={e => setF(p => ({ ...p, password: e.target.value }))} required autoComplete="current-password" placeholder="Admin@123" />
            <Input label="Tenant Slug" value={f.tenant} onChange={e => setF(p => ({ ...p, tenant: e.target.value }))} required placeholder="czium-dist" />
            {err && <p className="text-sm text-destructive bg-destructive/10 px-3 py-2 rounded-md">{err}</p>}
            <Button type="submit" variant="primary" className="w-full" disabled={busy} size="lg">
              {busy ? <><Spinner size="sm" /> Signing in…</> : 'Sign in'}
            </Button>
          </form>
        </Card>
        <p className="text-center text-xs text-muted-foreground mt-4">Demo: admin@metrodist.com / Admin@123</p>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// SIDEBAR NAV
// ═══════════════════════════════════════════════════════════
const NAV = [
  { key: 'dashboard',    icon: '📊', label: 'Dashboard' },
  { key: 'orders',       icon: '🛒', label: 'Sales Orders' },
  { key: 'customers',    icon: '🏪', label: 'Customers' },
  { key: 'products',     icon: '📦', label: 'Products' },
  { key: 'inventory',    icon: '🏗️', label: 'Inventory' },
  { key: 'production',   icon: '⚙️', label: 'Production' },
  { key: 'reps',         icon: '👨‍💼', label: 'Sales Reps' },
  { key: 'areas',        icon: '📍', label: 'Areas' },
  { key: 'distributors', icon: '🚚', label: 'Distributors' },
  { key: 'suppliers',    icon: '🏭', label: 'Suppliers' },
  { key: 'purchasing',   icon: '🧾', label: 'Purchasing' },
  { key: 'invoices',     icon: '💳', label: 'Invoices' },
  { key: 'reports',      icon: '📈', label: 'Reports' },
  { key: 'settings',     icon: '⚙️', label: 'Settings' },
];

function Sidebar({ page, onNav, user, onLogout, dark, onToggleTheme, collapsed, onToggle }) {
  return (
    <aside className={`h-screen flex flex-col bg-sidebar text-sidebar-foreground border-r border-sidebar-border transition-all duration-200 ${collapsed ? 'w-14' : 'w-56'} shrink-0`}>
      {/* Logo */}
      <div className="flex items-center gap-3 px-4 py-4 border-b border-sidebar-border">
        <div className="w-8 h-8 bg-primary rounded-lg flex items-center justify-center text-base shrink-0">🌶️</div>
        {!collapsed && <span className="font-bold text-sm text-white truncate">CZium Distribution</span>}
      </div>

      {/* Nav */}
      <nav className="flex-1 overflow-y-auto py-2 no-scrollbar">
        {NAV.map(n => (
          <button key={n.key} onClick={() => onNav(n.key)}
            className={`w-full flex items-center gap-3 px-3 py-2 text-sm font-medium transition-colors duration-150 rounded-md mx-1 ${collapsed ? 'justify-center' : ''} ${
              page === n.key ? 'bg-primary text-white' : 'text-sidebar-foreground hover:bg-white/10'
            }`} title={collapsed ? n.label : undefined}>
            <span className="text-base shrink-0">{n.icon}</span>
            {!collapsed && <span className="truncate">{n.label}</span>}
          </button>
        ))}
      </nav>

      {/* Footer */}
      <div className="border-t border-sidebar-border p-2 space-y-1">
        <button onClick={onToggleTheme}
          className="w-full flex items-center gap-3 px-3 py-2 text-sm text-sidebar-foreground hover:bg-white/10 rounded-md transition-colors"
          title={collapsed ? (dark ? 'Light mode' : 'Dark mode') : undefined}>
          <span>{dark ? '☀️' : '🌙'}</span>
          {!collapsed && <span>{dark ? 'Light mode' : 'Dark mode'}</span>}
        </button>
        {!collapsed && user && (
          <div className="px-3 py-2 text-xs text-sidebar-foreground/60 truncate">{user.name}</div>
        )}
        <button onClick={onLogout}
          className="w-full flex items-center gap-3 px-3 py-2 text-sm text-sidebar-foreground hover:bg-white/10 rounded-md transition-colors"
          title={collapsed ? 'Sign out' : undefined}>
          <span>🚪</span>
          {!collapsed && <span>Sign out</span>}
        </button>
      </div>
    </aside>
  );
}

// ═══════════════════════════════════════════════════════════
// DASHBOARD PAGE
// ═══════════════════════════════════════════════════════════
function DashboardPage() {
  const { data: dash, loading } = useFetch('dashboard');
  const { data: areaData } = useFetch('area-analytics', { from: new Date(new Date().setDate(1)).toISOString().slice(0,10), to: new Date().toISOString().slice(0,10) });

  if (loading) return <div className="flex items-center justify-center py-32"><Spinner size="lg" /></div>;
  const d = dash || {};

  return (
    <div className="p-6 space-y-6 fade-in">
      <PageHeader title="Dashboard" subtitle={new Date().toLocaleDateString('en-LK', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })} />

      {/* KPI row */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KpiCard icon="🛒" label="Today's Orders" value={fmtN(d.today_orders?.count || 0)} sub={`${fmtN(d.today_orders?.items || 0)} items`} color="primary" />
        <KpiCard icon="💰" label="Today's Revenue" value={fmt(d.today_orders?.revenue || 0)} sub="cash + credit" color="success" />
        <KpiCard icon="💵" label="Cash Collected" value={fmt(d.today_orders?.cash || 0)} color="cyan" />
        <KpiCard icon="📋" label="Credit Sales" value={fmt(d.today_orders?.credit || 0)} color="warning" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Area performance */}
        <Card>
          <CardHeader><CardTitle>Area Performance (This Month)</CardTitle></CardHeader>
          {areaData?.areas?.length ? (
            <BarChart
              data={areaData.areas.slice(0,8)}
              labelKey="name"
              valueKey="revenue"
              format={v => `Rs. ${(+v/1000).toFixed(0)}k`}
            />
          ) : <EmptyState icon="📍" title="No area data yet" />}
        </Card>

        {/* Product performance */}
        <Card>
          <CardHeader><CardTitle>Top Products (This Month)</CardTitle></CardHeader>
          {areaData?.products?.length ? (
            <BarChart
              data={areaData.products.slice(0,8)}
              labelKey="name"
              valueKey="units_sold"
              color="hsl(var(--success))"
              format={v => `${fmtN(v)} pkts`}
            />
          ) : <EmptyState icon="📦" title="No product data yet" />}
        </Card>
      </div>

      {/* Recent orders */}
      <Card padding={false}>
        <div className="p-4 border-b border-border">
          <CardTitle>Recent Orders</CardTitle>
        </div>
        <Table
          columns={[
            { key: 'order_number', header: 'Order #', render: v => <span className="font-mono text-xs">{v}</span> },
            { key: 'customer_name', header: 'Customer' },
            { key: 'order_date',  header: 'Date', render: v => new Date(v).toLocaleDateString() },
            { key: 'total_amount', header: 'Amount', render: v => <span className="font-semibold">{fmt(v)}</span> },
            { key: 'payment_mode', header: 'Mode',   render: v => <StatusBadge status={v || 'credit'} /> },
            { key: 'status',       header: 'Status', render: v => <StatusBadge status={v} /> },
          ]}
          data={d.recent_orders || []}
          emptyText="No recent orders"
        />
      </Card>

      {/* Alerts */}
      {(d.low_stock_count > 0 || d.raw_material_alerts > 0) && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {d.low_stock_count > 0 && (
            <Card className="border-warning/50 bg-warning/5">
              <div className="flex items-center gap-3">
                <span className="text-2xl">⚠️</span>
                <div>
                  <p className="font-semibold text-warning">{d.low_stock_count} Products Low on Stock</p>
                  <p className="text-xs text-muted-foreground">Check inventory for reorder</p>
                </div>
              </div>
            </Card>
          )}
          {d.raw_material_alerts > 0 && (
            <Card className="border-destructive/50 bg-destructive/5">
              <div className="flex items-center gap-3">
                <span className="text-2xl">🚨</span>
                <div>
                  <p className="font-semibold text-destructive">{d.raw_material_alerts} Raw Materials Below Reorder Point</p>
                  <p className="text-xs text-muted-foreground">Place purchase orders urgently</p>
                </div>
              </div>
            </Card>
          )}
        </div>
      )}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// SALES REPS PAGE
// ═══════════════════════════════════════════════════════════
function RepsPage() {
  const { data, loading, reload } = useFetch('sales-reps');
  const [modal, setModal] = useState(null);
  const [form, setForm]   = useState({ name: '', phone: '', route_name: '' });
  const [busy, setBusy]   = useState(false);
  const [toast, setToast] = useState(null);
  const reps = data || [];

  const save = async () => {
    setBusy(true);
    try {
      if (modal?.id) await Api.put(`sales-reps/${modal.id}`, form);
      else           await Api.post('sales-reps', form);
      setModal(null); reload();
      setToast({ message: 'Saved successfully', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  return (
    <div className="p-6 fade-in">
      <PageHeader
        title="Sales Reps"
        subtitle="Manage field sales representatives and their performance"
        actions={<Button onClick={() => { setForm({ name: '', phone: '', route_name: '' }); setModal({}); }}>+ Add Rep</Button>}
      />

      {loading ? <div className="flex justify-center py-32"><Spinner size="lg" /></div> : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {reps.map(rep => {
            const pct = rep.target_amount > 0 ? Math.round((rep.achieved_amount / rep.target_amount) * 100) : 0;
            return (
              <Card key={rep.id} className="hover:shadow-md transition-shadow">
                <div className="flex items-start justify-between mb-3">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center text-lg">👤</div>
                    <div>
                      <p className="font-semibold text-foreground">{rep.name}</p>
                      <p className="text-xs text-muted-foreground">{rep.phone || 'No phone'}</p>
                    </div>
                  </div>
                  <StatusBadge status={rep.is_active ? 'Active' : 'Inactive'} />
                </div>
                {rep.route_name && <p className="text-xs text-muted-foreground mb-3">📍 {rep.route_name}</p>}
                <div className="space-y-2 border-t border-border pt-3 mt-3">
                  <div className="flex justify-between text-xs">
                    <span className="text-muted-foreground">Today's Sales</span>
                    <span className="font-semibold">{fmt(rep.today_sales || 0)}</span>
                  </div>
                  <div className="flex justify-between text-xs">
                    <span className="text-muted-foreground">Monthly Target</span>
                    <span className="font-semibold">{fmt(rep.target_amount || 0)}</span>
                  </div>
                  <div className="flex justify-between text-xs mb-1.5">
                    <span className="text-muted-foreground">Achievement</span>
                    <span className={`font-bold ${pct >= 100 ? 'text-success' : pct >= 70 ? 'text-warning' : 'text-destructive'}`}>{pct}%</span>
                  </div>
                  <ProgressBar value={pct} max={100} color={pct >= 100 ? 'success' : pct >= 70 ? 'warning' : 'danger'} />
                </div>
                <Button variant="outline" size="sm" className="w-full mt-3" onClick={() => { setForm(rep); setModal(rep); }}>Edit Rep</Button>
              </Card>
            );
          })}
          {!reps.length && <EmptyState icon="👨‍💼" title="No sales reps yet" description="Add your first field sales representative" className="col-span-full" />}
        </div>
      )}

      <Modal open={!!modal} onClose={() => setModal(null)} title={modal?.id ? 'Edit Rep' : 'Add Sales Rep'}
        footer={<><Button variant="secondary" onClick={() => setModal(null)}>Cancel</Button><Button onClick={save} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Save'}</Button></>}>
        <div className="space-y-4">
          <Input label="Full Name *" value={form.name || ''} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} required />
          <Input label="Phone" value={form.phone || ''} onChange={e => setForm(p => ({ ...p, phone: e.target.value }))} />
          <Input label="Route Name" value={form.route_name || ''} onChange={e => setForm(p => ({ ...p, route_name: e.target.value }))} placeholder="e.g. Kalmunai North Route" />
        </div>
      </Modal>
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// AREAS PAGE
// ═══════════════════════════════════════════════════════════
function AreasPage() {
  const today  = new Date().toISOString().slice(0, 10);
  const month0 = new Date(new Date().setDate(1)).toISOString().slice(0, 10);
  const { data: analytics, loading } = useFetch('area-analytics', { from: month0, to: today });
  const { data: areaList } = useFetch('areas');
  const [modal, setModal] = useState(null);
  const [form, setForm]   = useState({ name: '', district: '' });
  const [busy, setBusy]   = useState(false);
  const [toast, setToast] = useState(null);

  const save = async () => {
    setBusy(true);
    try {
      if (modal?.id) await Api.put(`areas/${modal.id}`, form);
      else           await Api.post('areas', form);
      setModal(null);
      setToast({ message: 'Area saved', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  const areas = analytics?.areas || [];
  const products = analytics?.products || [];

  return (
    <div className="p-6 fade-in">
      <PageHeader
        title="Area Analytics"
        subtitle="Sales performance by territory"
        actions={<Button onClick={() => { setForm({ name: '', district: '' }); setModal({}); }}>+ Add Area</Button>}
      />

      {loading ? <div className="flex justify-center py-32"><Spinner size="lg" /></div> : (
        <div className="space-y-6">
          {/* Area comparison */}
          <Card>
            <CardHeader>
              <CardTitle>Monthly Revenue by Area</CardTitle>
              <span className="text-xs text-muted-foreground">{month0} – {today}</span>
            </CardHeader>
            {areas.length ? (
              <BarChart data={areas} labelKey="name" valueKey="revenue" format={v => `Rs. ${(+v/1000).toFixed(1)}k`} />
            ) : <EmptyState icon="📍" title="No sales data yet" />}
          </Card>

          {/* Area cards */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            {areas.map(area => (
              <Card key={area.id}>
                <div className="flex items-start justify-between mb-3">
                  <div>
                    <p className="font-semibold text-foreground">{area.name}</p>
                    <p className="text-xs text-muted-foreground">{area.district || 'No district'}</p>
                  </div>
                  <span className="text-xl">📍</span>
                </div>
                <div className="space-y-1.5 text-xs">
                  <div className="flex justify-between"><span className="text-muted-foreground">Revenue</span><span className="font-semibold">{fmt(area.revenue)}</span></div>
                  <div className="flex justify-between"><span className="text-muted-foreground">Orders</span><span className="font-semibold">{fmtN(area.orders_count)}</span></div>
                  <div className="flex justify-between"><span className="text-muted-foreground">Cash</span><span className="font-semibold text-success">{fmt(area.cash_sales)}</span></div>
                  <div className="flex justify-between"><span className="text-muted-foreground">Credit</span><span className="font-semibold text-warning">{fmt(area.credit_sales)}</span></div>
                </div>
              </Card>
            ))}
          </div>

          {/* Product breakdown */}
          <Card>
            <CardHeader><CardTitle>Product-wise Sales (This Month)</CardTitle></CardHeader>
            <Table
              columns={[
                { key: 'name',          header: 'Product' },
                { key: 'product_category', header: 'Category', render: v => v ? <Badge variant="default">{v}</Badge> : '—' },
                { key: 'units_sold',    header: 'Units Sold', render: v => <span className="font-semibold">{fmtN(v)}</span> },
                { key: 'revenue',       header: 'Revenue',    render: v => fmt(v) },
              ]}
              data={products}
              emptyText="No product data yet"
            />
          </Card>
        </div>
      )}

      <Modal open={!!modal} onClose={() => setModal(null)} title={modal?.id ? 'Edit Area' : 'Add Area'}
        footer={<><Button variant="secondary" onClick={() => setModal(null)}>Cancel</Button><Button onClick={save} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Save'}</Button></>}>
        <div className="space-y-4">
          <Input label="Area Name *" value={form.name || ''} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} placeholder="e.g. Kalmunai" required />
          <Input label="District" value={form.district || ''} onChange={e => setForm(p => ({ ...p, district: e.target.value }))} placeholder="e.g. Ampara" />
        </div>
      </Modal>
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// PRODUCTION PAGE
// ═══════════════════════════════════════════════════════════
function ProductionPage() {
  const { data, loading, reload } = useFetch('production', { from: new Date(new Date().setDate(1)).toISOString().slice(0,10), to: new Date().toISOString().slice(0,10) });
  const { data: rawMats } = useFetch('raw-materials');
  const { data: products } = useFetch('products');
  const [tab, setTab]     = useState('batches');
  const [modal, setModal] = useState(null);
  const [form, setForm]   = useState({ product_id: '', planned_qty: '', production_date: new Date().toISOString().slice(0,10), notes: '' });
  const [busy, setBusy]   = useState(false);
  const [toast, setToast] = useState(null);

  const batches = Array.isArray(data) ? data : [];
  const materials = Array.isArray(rawMats) ? rawMats : [];
  const lowStock = materials.filter(m => +m.current_stock <= +m.reorder_point);

  const createBatch = async () => {
    setBusy(true);
    try { await Api.post('production', form); setModal(null); reload(); setToast({ message: 'Batch created', type: 'success' }); }
    catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  const updateStatus = async (id, action) => {
    try {
      await Api.put(`production/${id}/${action}`, {});
      reload(); setToast({ message: `Batch ${action === 'start' ? 'started' : 'completed'}`, type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
  };

  const statusColor = { Planned: 'default', 'In Progress': 'warning', Completed: 'success', Cancelled: 'danger' };

  return (
    <div className="p-6 fade-in">
      <PageHeader
        title="Production"
        subtitle="Manage masala production batches and raw materials"
        actions={<Button onClick={() => { setForm({ product_id: '', planned_qty: '', production_date: new Date().toISOString().slice(0,10), notes: '' }); setModal({}); }}>+ New Batch</Button>}
      />

      <Tabs className="mb-6" tabs={[
        { key: 'batches',  label: 'Production Batches', icon: '⚙️' },
        { key: 'materials', label: `Raw Materials${lowStock.length ? ` (${lowStock.length} low)` : ''}`, icon: '📦' },
      ]} active={tab} onChange={setTab} />

      {loading ? <div className="flex justify-center py-32"><Spinner size="lg" /></div> : (
        tab === 'batches' ? (
          <div className="space-y-4">
            {/* Status pipeline cards */}
            {['Planned', 'In Progress', 'Completed'].map(status => {
              const bs = batches.filter(b => b.status === status);
              return (
                <Card key={status} padding={false}>
                  <div className="p-4 border-b border-border flex items-center gap-3">
                    <Badge variant={statusColor[status]}>{status}</Badge>
                    <span className="text-sm text-muted-foreground">{bs.length} batch{bs.length !== 1 ? 'es' : ''}</span>
                  </div>
                  {bs.length === 0 ? (
                    <div className="p-4 text-center text-sm text-muted-foreground">None</div>
                  ) : (
                    <div className="divide-y divide-border">
                      {bs.map(b => (
                        <div key={b.id} className="p-4 flex items-center gap-4">
                          <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 mb-1">
                              <span className="text-sm font-semibold text-foreground">{b.batch_number}</span>
                              <Badge variant="muted">{b.product_name}</Badge>
                            </div>
                            <div className="flex items-center gap-4 text-xs text-muted-foreground flex-wrap">
                              <span>Planned: {fmtN(b.planned_qty)} packets</span>
                              {b.actual_qty && <span>Actual: {fmtN(b.actual_qty)} packets</span>}
                              <span>📅 {b.production_date}</span>
                            </div>
                          </div>
                          <div className="flex gap-2 shrink-0">
                            {b.status === 'Planned'     && <Button size="sm" variant="secondary" onClick={() => updateStatus(b.id, 'start')}>Start</Button>}
                            {b.status === 'In Progress' && <Button size="sm" variant="success"   onClick={() => updateStatus(b.id, 'complete')}>Complete</Button>}
                          </div>
                        </div>
                      ))}
                    </div>
                  )}
                </Card>
              );
            })}
          </div>
        ) : (
          <Card padding={false}>
            {lowStock.length > 0 && (
              <div className="p-4 bg-destructive/5 border-b border-destructive/20">
                <p className="text-sm font-medium text-destructive">⚠️ {lowStock.length} materials below reorder point: {lowStock.map(m => m.name).join(', ')}</p>
              </div>
            )}
            <Table
              columns={[
                { key: 'name',          header: 'Material' },
                { key: 'unit',          header: 'Unit' },
                { key: 'current_stock', header: 'In Stock',      render: (v, row) => <span className={+v <= +row.reorder_point ? 'text-destructive font-bold' : 'font-semibold'}>{v} {row.unit}</span> },
                { key: 'reorder_point', header: 'Reorder Point', render: v => `${v}` },
                { key: 'cost_per_unit', header: 'Unit Cost',     render: v => `Rs. ${v}` },
              ]}
              data={materials}
              emptyText="No raw materials"
            />
          </Card>
        )
      )}

      <Modal open={!!modal} onClose={() => setModal(null)} title="New Production Batch"
        footer={<><Button variant="secondary" onClick={() => setModal(null)}>Cancel</Button><Button onClick={createBatch} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Create Batch'}</Button></>}>
        <div className="space-y-4">
          <Select label="Product *" value={form.product_id} onChange={e => setForm(p => ({ ...p, product_id: e.target.value }))} required>
            <option value="">Select product…</option>
            {(Array.isArray(products) ? products : []).filter(p => p.status === 'Active').map(p => (
              <option key={p.id} value={p.id}>{p.name} ({p.sku})</option>
            ))}
          </Select>
          <Input label="Planned Quantity (packets) *" type="number" value={form.planned_qty} onChange={e => setForm(p => ({ ...p, planned_qty: e.target.value }))} required />
          <Input label="Production Date *" type="date" value={form.production_date} onChange={e => setForm(p => ({ ...p, production_date: e.target.value }))} required />
          <Textarea label="Notes" value={form.notes} onChange={e => setForm(p => ({ ...p, notes: e.target.value }))} />
        </div>
      </Modal>
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// DISTRIBUTORS PAGE
// ═══════════════════════════════════════════════════════════
function DistributorsPage() {
  const { data, loading, reload } = useFetch('distributors');
  const { data: areas } = useFetch('areas');
  const [modal, setModal] = useState(null);
  const [form, setForm]   = useState({ name: '', contact_name: '', phone: '', area_id: '', credit_limit: '' });
  const [busy, setBusy]   = useState(false);
  const [toast, setToast] = useState(null);
  const dists = Array.isArray(data) ? data : [];
  const areaList = Array.isArray(areas) ? areas : [];

  const save = async () => {
    setBusy(true);
    try {
      if (modal?.id) await Api.put(`distributors/${modal.id}`, form);
      else           await Api.post('distributors', form);
      setModal(null); reload();
      setToast({ message: 'Saved', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  return (
    <div className="p-6 fade-in">
      <PageHeader
        title="Distributors"
        subtitle="Manage distributors, stock and outstanding payments"
        actions={<Button onClick={() => { setForm({ name: '', contact_name: '', phone: '', area_id: '', credit_limit: '' }); setModal({}); }}>+ Add Distributor</Button>}
      />

      {loading ? <div className="flex justify-center py-32"><Spinner size="lg" /></div> : (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {dists.map(d => (
            <Card key={d.id} className="hover:shadow-md transition-shadow">
              <div className="flex items-start justify-between mb-3">
                <div>
                  <p className="font-semibold text-foreground">{d.name}</p>
                  {d.contact_name && <p className="text-xs text-muted-foreground">{d.contact_name}</p>}
                  {d.phone        && <p className="text-xs text-muted-foreground">{d.phone}</p>}
                </div>
                {+d.outstanding > 0 && (
                  <Badge variant="danger">Rs. {(+d.outstanding/1000).toFixed(1)}k due</Badge>
                )}
              </div>
              {d.area_name && <p className="text-xs text-muted-foreground mb-3">📍 {d.area_name}</p>}
              <div className="space-y-1.5 text-xs border-t border-border pt-3">
                <div className="flex justify-between"><span className="text-muted-foreground">Credit Limit</span><span className="font-semibold">{fmt(d.credit_limit)}</span></div>
                <div className="flex justify-between"><span className="text-muted-foreground">Outstanding</span><span className={`font-semibold ${+d.outstanding > 0 ? 'text-destructive' : 'text-success'}`}>{fmt(d.outstanding || 0)}</span></div>
              </div>
              <Button variant="outline" size="sm" className="w-full mt-3" onClick={() => { setForm(d); setModal(d); }}>Edit</Button>
            </Card>
          ))}
          {!dists.length && <EmptyState icon="🚚" title="No distributors yet" description="Add your first distributor" />}
        </div>
      )}

      <Modal open={!!modal} onClose={() => setModal(null)} title={modal?.id ? 'Edit Distributor' : 'Add Distributor'}
        footer={<><Button variant="secondary" onClick={() => setModal(null)}>Cancel</Button><Button onClick={save} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Save'}</Button></>}>
        <div className="space-y-4">
          <Input label="Business Name *" value={form.name || ''} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} required />
          <Input label="Contact Person"  value={form.contact_name || ''} onChange={e => setForm(p => ({ ...p, contact_name: e.target.value }))} />
          <Input label="Phone"           value={form.phone || ''} onChange={e => setForm(p => ({ ...p, phone: e.target.value }))} />
          <Select label="Area" value={form.area_id || ''} onChange={e => setForm(p => ({ ...p, area_id: e.target.value }))}>
            <option value="">Select area…</option>
            {areaList.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}
          </Select>
          <Input label="Credit Limit (Rs.)" type="number" value={form.credit_limit || ''} onChange={e => setForm(p => ({ ...p, credit_limit: e.target.value }))} />
        </div>
      </Modal>
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// GENERIC LIST PAGE (Orders, Customers, Products, etc.)
// ═══════════════════════════════════════════════════════════
function GenericPage({ title, icon, endpoint, columns, addLabel, renderForm, emptyIcon, subtitle }) {
  const [search, setSearch] = useState('');
  const { data, loading, reload } = useFetch(endpoint, search ? { search } : undefined, [search]);
  const [modal, setModal] = useState(null);
  const [toast, setToast] = useState(null);
  const list = Array.isArray(data) ? data : (data?.data || data?.items || []);

  return (
    <div className="p-6 fade-in">
      <PageHeader title={`${icon} ${title}`} subtitle={subtitle}
        actions={addLabel ? <Button onClick={() => setModal({})}>+ {addLabel}</Button> : null} />
      <Card padding={false}>
        <div className="p-4 border-b border-border">
          <SearchInput value={search} onChange={setSearch} placeholder={`Search ${title.toLowerCase()}…`} className="max-w-sm" />
        </div>
        <Table columns={columns} data={list} loading={loading} emptyText={`No ${title.toLowerCase()} found`} />
      </Card>
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// ORDERS PAGE
// ═══════════════════════════════════════════════════════════
function OrdersPage() {
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState({ status: '', payment_mode: '' });
  const { data, loading } = useFetch('orders', { search, ...filters });
  const orders = Array.isArray(data) ? data : (data?.data || []);
  const [selected, setSelected] = useState(null);
  const [toast, setToast] = useState(null);

  const shareWhatsApp = async (id) => {
    try { const r = await Api.get(`whatsapp-invoice/${id}`); window.open(r.whatsapp_url, '_blank'); }
    catch (e) { setToast({ message: e.message, type: 'danger' }); }
  };

  return (
    <div className="p-6 fade-in">
      <PageHeader title="Sales Orders" subtitle="All sales transactions" />
      <Card padding={false}>
        <div className="p-4 border-b border-border flex flex-wrap gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search orders…" className="w-64" />
          <Select value={filters.status} onChange={e => setFilters(p => ({ ...p, status: e.target.value }))} className="w-36">
            <option value="">All Status</option>
            {['Draft','Pending','Confirmed','Shipped','Delivered','Cancelled'].map(s => <option key={s}>{s}</option>)}
          </Select>
          <Select value={filters.payment_mode} onChange={e => setFilters(p => ({ ...p, payment_mode: e.target.value }))} className="w-36">
            <option value="">All Modes</option>
            <option value="cash">Cash</option>
            <option value="credit">Credit</option>
          </Select>
        </div>
        <Table
          loading={loading}
          onRowClick={setSelected}
          columns={[
            { key: 'order_number',   header: 'Order #', render: v => <span className="font-mono text-xs font-semibold">{v}</span> },
            { key: 'customer_name',  header: 'Customer' },
            { key: 'order_date',     header: 'Date', render: v => new Date(v).toLocaleDateString('en-LK') },
            { key: 'total_amount',   header: 'Total', render: v => <span className="font-semibold">{fmt(v)}</span> },
            { key: 'payment_mode',   header: 'Mode', render: v => <StatusBadge status={v || 'credit'} /> },
            { key: 'payment_status', header: 'Payment', render: v => <StatusBadge status={v || 'Unpaid'} /> },
            { key: 'status',         header: 'Status', render: v => <StatusBadge status={v} /> },
            { key: 'id', header: '', render: (v) => (
              <Button variant="ghost" size="xs" onClick={e => { e.stopPropagation(); shareWhatsApp(v); }} title="Share via WhatsApp">
                📱
              </Button>
            )},
          ]}
          data={orders}
          emptyText="No orders found"
        />
      </Card>
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// PLACEHOLDER for existing pages (Customers, Products, etc.)
// ═══════════════════════════════════════════════════════════
function ComingSoon({ title, icon }) {
  return (
    <div className="p-6 fade-in">
      <PageHeader title={`${icon} ${title}`} />
      <Card className="flex items-center justify-center py-24">
        <EmptyState icon={icon} title={title} description="This module is available — loading data from existing API endpoints." />
      </Card>
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// MAIN APP
// ═══════════════════════════════════════════════════════════
export default function App() {
  const [dark, toggleTheme] = useTheme();
  const { user, loading, login, logout } = useAuth();
  const [page, setPage]     = useState('dashboard');
  const [sidebarCollapsed, setSidebarCollapsed] = useState(window.innerWidth < 1024);

  useEffect(() => {
    const onResize = () => setSidebarCollapsed(window.innerWidth < 768);
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, []);

  if (loading) return (
    <div className="min-h-screen bg-background flex items-center justify-center">
      <div className="flex flex-col items-center gap-4">
        <div className="text-4xl">🌶️</div>
        <Spinner size="lg" />
        <p className="text-sm text-muted-foreground">Loading CZium Distribution…</p>
      </div>
    </div>
  );

  if (!user) return <LoginPage onLogin={login} />;

  const renderPage = () => {
    switch (page) {
      case 'dashboard':    return <DashboardPage />;
      case 'orders':       return <OrdersPage />;
      case 'reps':         return <RepsPage />;
      case 'areas':        return <AreasPage />;
      case 'production':   return <ProductionPage />;
      case 'distributors': return <DistributorsPage />;
      case 'customers':    return <ComingSoon title="Customers"  icon="🏪" />;
      case 'products':     return <ComingSoon title="Products"   icon="📦" />;
      case 'inventory':    return <ComingSoon title="Inventory"  icon="🏗️" />;
      case 'suppliers':    return <ComingSoon title="Suppliers"  icon="🏭" />;
      case 'purchasing':   return <ComingSoon title="Purchasing" icon="🧾" />;
      case 'invoices':     return <ComingSoon title="Invoices"   icon="💳" />;
      case 'reports':      return <ComingSoon title="Reports"    icon="📈" />;
      case 'settings':     return <ComingSoon title="Settings"   icon="⚙️" />;
      default:             return <DashboardPage />;
    }
  };

  return (
    <div className="flex h-screen overflow-hidden bg-background">
      <Sidebar
        page={page}
        onNav={setPage}
        user={user}
        onLogout={logout}
        dark={dark}
        onToggleTheme={toggleTheme}
        collapsed={sidebarCollapsed}
        onToggle={() => setSidebarCollapsed(c => !c)}
      />
      <main className="flex-1 overflow-y-auto">
        {/* Top bar */}
        <div className="sticky top-0 z-30 bg-background/80 backdrop-blur border-b border-border px-4 py-2.5 flex items-center gap-3">
          <button onClick={() => setSidebarCollapsed(c => !c)}
            className="text-muted-foreground hover:text-foreground p-1 rounded-md hover:bg-accent transition-colors">
            ☰
          </button>
          <div className="flex-1" />
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <span className="font-medium text-foreground">{user?.name}</span>
            <Badge variant="muted">{user?.role_name || user?.role_id}</Badge>
          </div>
        </div>
        {renderPage()}
      </main>
    </div>
  );
}
