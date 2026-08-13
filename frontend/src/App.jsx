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

  const login = async (email, password, tenant) => {
    const r = await Api.post('auth/login', { email, password, tenant });
    if (r.requires_totp) return r; // caller must prompt for a TOTP code and call verifyTotp
    Api.setToken(r.token); Api.setUser(r.user); setUser(r.user); return r;
  };
  const verifyTotp = async (pendingToken, code) => {
    const r = await Api.post('auth/verify-totp', { pending_token: pendingToken, code });
    Api.setToken(r.token); Api.setUser(r.user); setUser(r.user); return r;
  };
  const logout = async () => { try { await Api.post('auth/logout', {}); } catch {} Api.clearAuth(); setUser(null); };
  const refreshUser = async () => { const u = await Api.get('auth/me'); Api.setUser(u); setUser(u); return u; };
  return { user, loading, login, verifyTotp, logout, refreshUser };
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
function LoginPage({ onLogin, onVerifyTotp }) {
  const [f, setF]   = useState({ email: 'admin@metrodist.com', password: '', tenant: 'czium-dist' });
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const [pendingToken, setPendingToken] = useState(null);
  const [code, setCode] = useState('');

  const submit = async e => {
    e.preventDefault(); setErr(''); setBusy(true);
    try {
      const r = await onLogin(f.email, f.password, f.tenant);
      if (r?.requires_totp) setPendingToken(r.pending_token);
    }
    catch (e) { setErr(e.message || 'Login failed'); }
    finally { setBusy(false); }
  };

  const submitTotp = async e => {
    e.preventDefault(); setErr(''); setBusy(true);
    try { await onVerifyTotp(pendingToken, code); }
    catch (e) { setErr(e.message || 'Invalid code'); }
    finally { setBusy(false); }
  };

  if (pendingToken) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center p-4">
        <div className="w-full max-w-sm">
          <div className="text-center mb-8">
            <div className="w-14 h-14 bg-primary rounded-2xl flex items-center justify-center text-2xl mx-auto mb-4 shadow-md">🔒</div>
            <h1 className="text-2xl font-bold text-foreground">Two-Factor Verification</h1>
            <p className="text-sm text-muted-foreground mt-1">Enter the 6-digit code from your authenticator app</p>
          </div>
          <Card className="shadow-md">
            <form onSubmit={submitTotp} className="space-y-4">
              <Input label="Authentication Code" value={code} onChange={e => setCode(e.target.value)} required autoComplete="one-time-code" placeholder="123456" autoFocus />
              {err && <p className="text-sm text-destructive bg-destructive/10 px-3 py-2 rounded-md">{err}</p>}
              <Button type="submit" variant="primary" className="w-full" disabled={busy} size="lg">
                {busy ? <><Spinner size="sm" /> Verifying…</> : 'Verify'}
              </Button>
              <button type="button" className="text-xs text-muted-foreground hover:underline w-full text-center" onClick={() => { setPendingToken(null); setCode(''); setErr(''); }}>
                ← Back to login
              </button>
            </form>
          </Card>
        </div>
      </div>
    );
  }

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
// CUSTOMERS PAGE
// ═══════════════════════════════════════════════════════════
const emptyCustomer = { name: '', type: 'Retail', contact_name: '', email: '', phone: '', address: '', city: '', country: '', territory: '', credit_limit: '', payment_terms: 'Net 30', tax_number: '', status: 'Active', notes: '' };

function CustomerDetail({ id, onClose, onEdit }) {
  const { data, loading } = useFetch(id ? `customers/${id}` : null);
  const customer = data?.customer;
  const orders = data?.recent_orders || [];
  return (
    <Modal open={!!id} onClose={onClose} title={customer?.name || 'Customer'} size="lg"
      footer={<><Button variant="secondary" onClick={onClose}>Close</Button>{customer && <Button onClick={() => onEdit(customer)}>Edit</Button>}</>}>
      {loading ? <div className="flex justify-center py-16"><Spinner /></div> : customer && (
        <div className="space-y-5">
          <div className="flex items-start justify-between">
            <div>
              <p className="text-xs text-muted-foreground font-mono">{customer.code}</p>
              <p className="text-lg font-bold text-foreground">{customer.name}</p>
              <p className="text-sm text-muted-foreground">{customer.contact_name}</p>
            </div>
            <StatusBadge status={customer.status} />
          </div>
          <div className="grid grid-cols-2 gap-4 text-sm border-t border-border pt-4">
            <div><p className="text-xs text-muted-foreground">Email</p><p className="font-medium">{customer.email || '—'}</p></div>
            <div><p className="text-xs text-muted-foreground">Phone</p><p className="font-medium">{customer.phone || '—'}</p></div>
            <div><p className="text-xs text-muted-foreground">Type</p><p className="font-medium">{customer.type}</p></div>
            <div><p className="text-xs text-muted-foreground">Territory</p><p className="font-medium">{customer.territory || '—'}</p></div>
            <div><p className="text-xs text-muted-foreground">Credit Limit</p><p className="font-medium">{fmt(customer.credit_limit)}</p></div>
            <div><p className="text-xs text-muted-foreground">Outstanding Balance</p><p className={`font-medium ${+customer.outstanding_balance > 0 ? 'text-destructive' : ''}`}>{fmt(customer.outstanding_balance || 0)}</p></div>
            <div><p className="text-xs text-muted-foreground">Payment Terms</p><p className="font-medium">{customer.payment_terms}</p></div>
            <div><p className="text-xs text-muted-foreground">Address</p><p className="font-medium">{[customer.address, customer.city, customer.country].filter(Boolean).join(', ') || '—'}</p></div>
          </div>
          <div className="border-t border-border pt-4">
            <p className="text-sm font-semibold text-foreground mb-2">Recent Orders</p>
            <Table
              columns={[
                { key: 'order_number', header: 'Order #', render: v => <span className="font-mono text-xs font-semibold">{v}</span> },
                { key: 'order_date', header: 'Date', render: v => new Date(v).toLocaleDateString('en-LK') },
                { key: 'total_amount', header: 'Total', render: v => fmt(v) },
                { key: 'status', header: 'Status', render: v => <StatusBadge status={v} /> },
              ]}
              data={orders} emptyText="No orders yet"
            />
          </div>
        </div>
      )}
    </Modal>
  );
}

function CustomersPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const { data, loading, reload } = useFetch('customers', { search, status }, [search, status]);
  const list = data?.data || [];
  const [modal, setModal] = useState(null);
  const [form, setForm] = useState(emptyCustomer);
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState(null);
  const [detailId, setDetailId] = useState(null);

  const save = async () => {
    setBusy(true);
    try {
      if (modal?.id) await Api.put(`customers/${modal.id}`, form);
      else           await Api.post('customers', form);
      setModal(null); reload();
      setToast({ message: 'Customer saved', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  return (
    <div className="p-6 fade-in">
      <PageHeader title="Customers" subtitle="Manage customer accounts and credit"
        actions={<Button onClick={() => { setForm(emptyCustomer); setModal({}); }}>+ Add Customer</Button>} />
      <Card padding={false}>
        <div className="p-4 border-b border-border flex flex-wrap gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search name, code, email…" className="w-64" />
          <Select value={status} onChange={e => setStatus(e.target.value)} className="w-36">
            <option value="">All Status</option>
            <option>Active</option><option>Inactive</option><option>On Hold</option>
          </Select>
        </div>
        <Table
          loading={loading}
          onRowClick={row => setDetailId(row.id)}
          columns={[
            { key: 'code', header: 'Code', render: v => <span className="font-mono text-xs">{v}</span> },
            { key: 'name', header: 'Name' },
            { key: 'contact_name', header: 'Contact' },
            { key: 'territory', header: 'Territory', render: v => v || '—' },
            { key: 'credit_limit', header: 'Credit Limit', render: v => fmt(v) },
            { key: 'outstanding_balance', header: 'Outstanding', render: v => <span className={+v > 0 ? 'text-destructive font-medium' : ''}>{fmt(v || 0)}</span> },
            { key: 'status', header: 'Status', render: v => <StatusBadge status={v} /> },
            { key: 'id', header: '', render: (v, row) => (
              <Button variant="ghost" size="xs" onClick={e => { e.stopPropagation(); setForm(row); setModal(row); }}>Edit</Button>
            )},
          ]}
          data={list} emptyText="No customers found"
        />
      </Card>

      <Modal open={!!modal} onClose={() => setModal(null)} title={modal?.id ? 'Edit Customer' : 'Add Customer'} size="lg"
        footer={<><Button variant="secondary" onClick={() => setModal(null)}>Cancel</Button><Button onClick={save} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Save'}</Button></>}>
        <div className="grid grid-cols-2 gap-4">
          <Input label="Business Name *" value={form.name || ''} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} required className="col-span-2" />
          <Select label="Type" value={form.type || 'Retail'} onChange={e => setForm(p => ({ ...p, type: e.target.value }))}>
            <option>Retail</option><option>Wholesale</option><option>Distributor</option>
          </Select>
          <Select label="Status" value={form.status || 'Active'} onChange={e => setForm(p => ({ ...p, status: e.target.value }))}>
            <option>Active</option><option>Inactive</option><option>On Hold</option>
          </Select>
          <Input label="Contact Person *" value={form.contact_name || ''} onChange={e => setForm(p => ({ ...p, contact_name: e.target.value }))} required />
          <Input label="Phone" value={form.phone || ''} onChange={e => setForm(p => ({ ...p, phone: e.target.value }))} />
          <Input label="Email" type="email" value={form.email || ''} onChange={e => setForm(p => ({ ...p, email: e.target.value }))} className="col-span-2" />
          <Input label="Address" value={form.address || ''} onChange={e => setForm(p => ({ ...p, address: e.target.value }))} className="col-span-2" />
          <Input label="City" value={form.city || ''} onChange={e => setForm(p => ({ ...p, city: e.target.value }))} />
          <Input label="Country" value={form.country || ''} onChange={e => setForm(p => ({ ...p, country: e.target.value }))} />
          <Input label="Territory" value={form.territory || ''} onChange={e => setForm(p => ({ ...p, territory: e.target.value }))} />
          <Input label="Tax Number" value={form.tax_number || ''} onChange={e => setForm(p => ({ ...p, tax_number: e.target.value }))} />
          <Input label="Credit Limit (Rs.)" type="number" value={form.credit_limit || ''} onChange={e => setForm(p => ({ ...p, credit_limit: e.target.value }))} />
          <Select label="Payment Terms" value={form.payment_terms || 'Net 30'} onChange={e => setForm(p => ({ ...p, payment_terms: e.target.value }))}>
            <option>Net 15</option><option>Net 30</option><option>Net 60</option><option>Cash</option>
          </Select>
          <Textarea label="Notes" value={form.notes || ''} onChange={e => setForm(p => ({ ...p, notes: e.target.value }))} className="col-span-2" />
        </div>
      </Modal>

      <CustomerDetail id={detailId} onClose={() => setDetailId(null)} onEdit={c => { setForm(c); setModal(c); setDetailId(null); }} />
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// PRODUCTS PAGE
// ═══════════════════════════════════════════════════════════
const emptyProduct = { name: '', sku: '', barcode: '', brand: '', unit_of_measure: 'Piece', cost_price: '', sale_price: '', reorder_point: '', reorder_qty: '', costing_method: 'FIFO', status: 'Active', description: '' };

function ProductDetail({ id, onClose, onEdit }) {
  const { data, loading } = useFetch(id ? `products/${id}` : null);
  const product = data?.product;
  const stock = data?.stock || [];
  const moves = data?.movements || [];
  return (
    <Modal open={!!id} onClose={onClose} title={product?.name || 'Product'} size="lg"
      footer={<><Button variant="secondary" onClick={onClose}>Close</Button>{product && <Button onClick={() => onEdit(product)}>Edit</Button>}</>}>
      {loading ? <div className="flex justify-center py-16"><Spinner /></div> : product && (
        <div className="space-y-5">
          <div className="flex items-start justify-between">
            <div>
              <p className="text-xs text-muted-foreground font-mono">{product.sku}</p>
              <p className="text-lg font-bold text-foreground">{product.name}</p>
              <p className="text-sm text-muted-foreground">{product.brand}</p>
            </div>
            <StatusBadge status={product.status} />
          </div>
          <div className="grid grid-cols-2 gap-4 text-sm border-t border-border pt-4">
            <div><p className="text-xs text-muted-foreground">Cost Price</p><p className="font-medium">{fmt(product.cost_price)}</p></div>
            <div><p className="text-xs text-muted-foreground">Sale Price</p><p className="font-medium">{fmt(product.sale_price)}</p></div>
            <div><p className="text-xs text-muted-foreground">Unit</p><p className="font-medium">{product.unit_of_measure}</p></div>
            <div><p className="text-xs text-muted-foreground">Reorder Point</p><p className="font-medium">{fmtN(product.reorder_point)}</p></div>
          </div>
          <div className="border-t border-border pt-4">
            <p className="text-sm font-semibold text-foreground mb-2">Stock by Warehouse</p>
            <Table
              columns={[
                { key: 'warehouse_name', header: 'Warehouse' },
                { key: 'qty_on_hand', header: 'On Hand', render: v => fmtN(v) },
                { key: 'qty_reserved', header: 'Reserved', render: v => fmtN(v) },
                { key: 'qty_available', header: 'Available', render: v => <span className="font-semibold">{fmtN(v)}</span> },
              ]}
              data={stock} emptyText="No stock recorded"
            />
          </div>
          <div className="border-t border-border pt-4">
            <p className="text-sm font-semibold text-foreground mb-2">Recent Movements</p>
            <Table
              columns={[
                { key: 'created_at', header: 'Date', render: v => new Date(v).toLocaleDateString('en-LK') },
                { key: 'type', header: 'Type', render: v => <Badge variant={v === 'IN' ? 'success' : v === 'OUT' ? 'danger' : 'default'}>{v}</Badge> },
                { key: 'qty', header: 'Qty', render: v => fmtN(v) },
                { key: 'reason', header: 'Reason' },
              ]}
              data={moves} emptyText="No movements yet"
            />
          </div>
        </div>
      )}
    </Modal>
  );
}

function ProductsPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const { data, loading, reload } = useFetch('products', { search, status }, [search, status]);
  const list = data?.data || [];
  const [modal, setModal] = useState(null);
  const [form, setForm] = useState(emptyProduct);
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState(null);
  const [detailId, setDetailId] = useState(null);

  const save = async () => {
    setBusy(true);
    try {
      if (modal?.id) await Api.put(`products/${modal.id}`, form);
      else           await Api.post('products', form);
      setModal(null); reload();
      setToast({ message: 'Product saved', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  return (
    <div className="p-6 fade-in">
      <PageHeader title="Products" subtitle="Manage your product catalog"
        actions={<Button onClick={() => { setForm(emptyProduct); setModal({}); }}>+ Add Product</Button>} />
      <Card padding={false}>
        <div className="p-4 border-b border-border flex flex-wrap gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search name, SKU, brand…" className="w-64" />
          <Select value={status} onChange={e => setStatus(e.target.value)} className="w-40">
            <option value="">All Status</option>
            <option>Active</option><option>Inactive</option><option>Discontinued</option>
          </Select>
        </div>
        <Table
          loading={loading}
          onRowClick={row => setDetailId(row.id)}
          columns={[
            { key: 'sku', header: 'SKU', render: v => <span className="font-mono text-xs">{v}</span> },
            { key: 'name', header: 'Name' },
            { key: 'brand', header: 'Brand', render: v => v || '—' },
            { key: 'sale_price', header: 'Sale Price', render: v => fmt(v) },
            { key: 'total_stock', header: 'Stock', render: (v, row) => <span className={+v <= +row.reorder_point ? 'text-destructive font-semibold' : 'font-semibold'}>{fmtN(v || 0)}</span> },
            { key: 'status', header: 'Status', render: v => <StatusBadge status={v} /> },
            { key: 'id', header: '', render: (v, row) => (
              <Button variant="ghost" size="xs" onClick={e => { e.stopPropagation(); setForm(row); setModal(row); }}>Edit</Button>
            )},
          ]}
          data={list} emptyText="No products found"
        />
      </Card>

      <Modal open={!!modal} onClose={() => setModal(null)} title={modal?.id ? 'Edit Product' : 'Add Product'} size="lg"
        footer={<><Button variant="secondary" onClick={() => setModal(null)}>Cancel</Button><Button onClick={save} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Save'}</Button></>}>
        <div className="grid grid-cols-2 gap-4">
          <Input label="Product Name *" value={form.name || ''} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} required className="col-span-2" />
          <Input label="SKU *" value={form.sku || ''} onChange={e => setForm(p => ({ ...p, sku: e.target.value }))} required />
          <Input label="Barcode" value={form.barcode || ''} onChange={e => setForm(p => ({ ...p, barcode: e.target.value }))} />
          <Input label="Brand" value={form.brand || ''} onChange={e => setForm(p => ({ ...p, brand: e.target.value }))} />
          <Select label="Unit of Measure" value={form.unit_of_measure || 'Piece'} onChange={e => setForm(p => ({ ...p, unit_of_measure: e.target.value }))}>
            <option>Piece</option><option>Kg</option><option>Gram</option><option>Litre</option><option>Box</option><option>Packet</option>
          </Select>
          <Input label="Cost Price *" type="number" step="0.01" value={form.cost_price || ''} onChange={e => setForm(p => ({ ...p, cost_price: e.target.value }))} required />
          <Input label="Sale Price *" type="number" step="0.01" value={form.sale_price || ''} onChange={e => setForm(p => ({ ...p, sale_price: e.target.value }))} required />
          <Input label="Reorder Point" type="number" value={form.reorder_point || ''} onChange={e => setForm(p => ({ ...p, reorder_point: e.target.value }))} />
          <Input label="Reorder Qty" type="number" value={form.reorder_qty || ''} onChange={e => setForm(p => ({ ...p, reorder_qty: e.target.value }))} />
          <Select label="Costing Method" value={form.costing_method || 'FIFO'} onChange={e => setForm(p => ({ ...p, costing_method: e.target.value }))}>
            <option>FIFO</option><option>LIFO</option><option>Average</option>
          </Select>
          <Select label="Status" value={form.status || 'Active'} onChange={e => setForm(p => ({ ...p, status: e.target.value }))}>
            <option>Active</option><option>Inactive</option><option>Discontinued</option>
          </Select>
          <Textarea label="Description" value={form.description || ''} onChange={e => setForm(p => ({ ...p, description: e.target.value }))} className="col-span-2" />
        </div>
      </Modal>

      <ProductDetail id={detailId} onClose={() => setDetailId(null)} onEdit={p => { setForm(p); setModal(p); setDetailId(null); }} />
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// INVENTORY PAGE
// ═══════════════════════════════════════════════════════════
function InventoryPage() {
  const [search, setSearch] = useState('');
  const [alert, setAlert] = useState('');
  const { data, loading, reload } = useFetch('inventory', { search, alert }, [search, alert]);
  const { data: warehouses } = useFetch('warehouses');
  const { data: products } = useFetch('products');
  const list = data?.data || [];
  const whList = Array.isArray(warehouses) ? warehouses : [];
  const prodList = products?.data || [];
  const [modal, setModal] = useState(false);
  const [form, setForm] = useState({ product_id: '', warehouse_id: '', qty: '', type: 'ADJUSTMENT', reason: '', notes: '' });
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState(null);

  const save = async () => {
    setBusy(true);
    try {
      await Api.post('inventory', form);
      setModal(false); reload();
      setToast({ message: 'Stock movement recorded', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  return (
    <div className="p-6 fade-in">
      <PageHeader title="Inventory" subtitle="Stock levels across all warehouses"
        actions={<Button onClick={() => { setForm({ product_id: '', warehouse_id: '', qty: '', type: 'ADJUSTMENT', reason: '', notes: '' }); setModal(true); }}>+ Adjust Stock</Button>} />
      <Card padding={false}>
        <div className="p-4 border-b border-border flex flex-wrap gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search name, SKU…" className="w-64" />
          <Select value={alert} onChange={e => setAlert(e.target.value)} className="w-40">
            <option value="">All Stock</option>
            <option value="low">Low Stock</option>
            <option value="zero">Out of Stock</option>
          </Select>
        </div>
        <Table
          loading={loading}
          columns={[
            { key: 'sku', header: 'SKU', render: v => <span className="font-mono text-xs">{v}</span> },
            { key: 'name', header: 'Product' },
            { key: 'warehouse_name', header: 'Warehouse', render: v => v || '—' },
            { key: 'qty_on_hand', header: 'On Hand', render: v => fmtN(v || 0) },
            { key: 'qty_reserved', header: 'Reserved', render: v => fmtN(v || 0) },
            { key: 'qty_available', header: 'Available', render: (v, row) => <span className={+(v ?? row.qty_on_hand) <= +row.reorder_point ? 'text-destructive font-semibold' : 'font-semibold'}>{fmtN(v ?? row.qty_on_hand ?? 0)}</span> },
            { key: 'reorder_point', header: 'Reorder At', render: v => fmtN(v || 0) },
          ]}
          data={list} emptyText="No inventory records found"
        />
      </Card>

      <Modal open={modal} onClose={() => setModal(false)} title="Adjust Stock"
        footer={<><Button variant="secondary" onClick={() => setModal(false)}>Cancel</Button><Button onClick={save} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Record Movement'}</Button></>}>
        <div className="space-y-4">
          <Select label="Product *" value={form.product_id} onChange={e => setForm(p => ({ ...p, product_id: e.target.value }))} required>
            <option value="">Select product…</option>
            {prodList.map(p => <option key={p.id} value={p.id}>{p.name} ({p.sku})</option>)}
          </Select>
          <Select label="Warehouse *" value={form.warehouse_id} onChange={e => setForm(p => ({ ...p, warehouse_id: e.target.value }))} required>
            <option value="">Select warehouse…</option>
            {whList.map(w => <option key={w.id} value={w.id}>{w.name}</option>)}
          </Select>
          <Select label="Movement Type *" value={form.type} onChange={e => setForm(p => ({ ...p, type: e.target.value }))} required>
            <option value="IN">IN — Stock In</option>
            <option value="OUT">OUT — Stock Out</option>
            <option value="ADJUSTMENT">ADJUSTMENT — Correction</option>
            <option value="DAMAGED">DAMAGED</option>
            <option value="RETURN">RETURN</option>
            <option value="COUNT">COUNT — Physical Count</option>
          </Select>
          <Input label="Quantity *" type="number" value={form.qty} onChange={e => setForm(p => ({ ...p, qty: e.target.value }))} required />
          {form.type === 'ADJUSTMENT' && <p className="text-xs text-muted-foreground -mt-2">Use a negative number to reduce stock.</p>}
          <Input label="Reason *" value={form.reason} onChange={e => setForm(p => ({ ...p, reason: e.target.value }))} placeholder="e.g. Physical count correction" required />
          <Textarea label="Notes" value={form.notes} onChange={e => setForm(p => ({ ...p, notes: e.target.value }))} />
        </div>
      </Modal>
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// SUPPLIERS PAGE
// ═══════════════════════════════════════════════════════════
const emptySupplier = { name: '', contact_name: '', email: '', phone: '', address: '', city: '', country: '', payment_terms: 'Net 30', tax_number: '', status: 'Active', notes: '' };

function SuppliersPage() {
  const [search, setSearch] = useState('');
  const { data, loading, reload } = useFetch('suppliers', { search }, [search]);
  const list = data?.data || [];
  const [modal, setModal] = useState(null);
  const [form, setForm] = useState(emptySupplier);
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState(null);

  const save = async () => {
    setBusy(true);
    try {
      if (modal?.id) await Api.put(`suppliers/${modal.id}`, form);
      else           await Api.post('suppliers', form);
      setModal(null); reload();
      setToast({ message: 'Supplier saved', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  const remove = async (row) => {
    if (!confirm(`Delete supplier "${row.name}"?`)) return;
    try { await Api.delete(`suppliers/${row.id}`); reload(); setToast({ message: 'Supplier deleted', type: 'success' }); }
    catch (e) { setToast({ message: e.message, type: 'danger' }); }
  };

  return (
    <div className="p-6 fade-in">
      <PageHeader title="Suppliers" subtitle="Manage your supplier accounts"
        actions={<Button onClick={() => { setForm(emptySupplier); setModal({}); }}>+ Add Supplier</Button>} />
      <Card padding={false}>
        <div className="p-4 border-b border-border">
          <SearchInput value={search} onChange={setSearch} placeholder="Search name, code…" className="w-64" />
        </div>
        <Table
          loading={loading}
          columns={[
            { key: 'code', header: 'Code', render: v => <span className="font-mono text-xs">{v}</span> },
            { key: 'name', header: 'Name' },
            { key: 'contact_name', header: 'Contact' },
            { key: 'phone', header: 'Phone', render: v => v || '—' },
            { key: 'payment_terms', header: 'Terms' },
            { key: 'status', header: 'Status', render: v => <StatusBadge status={v} /> },
            { key: 'id', header: '', render: (v, row) => (
              <div className="flex gap-1">
                <Button variant="ghost" size="xs" onClick={e => { e.stopPropagation(); setForm(row); setModal(row); }}>Edit</Button>
                <Button variant="ghost" size="xs" onClick={e => { e.stopPropagation(); remove(row); }} className="text-destructive">Delete</Button>
              </div>
            )},
          ]}
          data={list} emptyText="No suppliers found"
        />
      </Card>

      <Modal open={!!modal} onClose={() => setModal(null)} title={modal?.id ? 'Edit Supplier' : 'Add Supplier'} size="lg"
        footer={<><Button variant="secondary" onClick={() => setModal(null)}>Cancel</Button><Button onClick={save} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Save'}</Button></>}>
        <div className="grid grid-cols-2 gap-4">
          <Input label="Business Name *" value={form.name || ''} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} required className="col-span-2" />
          <Input label="Contact Person *" value={form.contact_name || ''} onChange={e => setForm(p => ({ ...p, contact_name: e.target.value }))} required />
          <Input label="Phone" value={form.phone || ''} onChange={e => setForm(p => ({ ...p, phone: e.target.value }))} />
          <Input label="Email" type="email" value={form.email || ''} onChange={e => setForm(p => ({ ...p, email: e.target.value }))} className="col-span-2" />
          <Input label="Address" value={form.address || ''} onChange={e => setForm(p => ({ ...p, address: e.target.value }))} className="col-span-2" />
          <Input label="City" value={form.city || ''} onChange={e => setForm(p => ({ ...p, city: e.target.value }))} />
          <Input label="Country" value={form.country || ''} onChange={e => setForm(p => ({ ...p, country: e.target.value }))} />
          <Select label="Payment Terms" value={form.payment_terms || 'Net 30'} onChange={e => setForm(p => ({ ...p, payment_terms: e.target.value }))}>
            <option>Net 15</option><option>Net 30</option><option>Net 60</option><option>Cash</option>
          </Select>
          <Input label="Tax Number" value={form.tax_number || ''} onChange={e => setForm(p => ({ ...p, tax_number: e.target.value }))} />
          <Select label="Status" value={form.status || 'Active'} onChange={e => setForm(p => ({ ...p, status: e.target.value }))}>
            <option>Active</option><option>Inactive</option>
          </Select>
          <Textarea label="Notes" value={form.notes || ''} onChange={e => setForm(p => ({ ...p, notes: e.target.value }))} className="col-span-2" />
        </div>
      </Modal>
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// PURCHASING (PURCHASE ORDERS) PAGE
// ═══════════════════════════════════════════════════════════
function PurchaseOrderDetail({ id, onClose, onReceive }) {
  const { data, loading, reload } = useFetch(id ? `purchase-orders/${id}` : null);
  const po = data?.po;
  const items = data?.items || [];
  const [receiveModal, setReceiveModal] = useState(false);
  const [receiveQtys, setReceiveQtys] = useState({});
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState(null);

  const openReceive = () => {
    const q = {};
    items.forEach(it => { q[it.product_id] = Math.max(0, (+it.qty_ordered) - (+it.qty_received)); });
    setReceiveQtys(q); setReceiveModal(true);
  };

  const submitReceive = async () => {
    setBusy(true);
    try {
      const receiveItems = Object.entries(receiveQtys).filter(([, v]) => +v > 0).map(([product_id, qty_received]) => ({ product_id, qty_received: +qty_received }));
      if (!receiveItems.length) throw new Error('Enter at least one quantity to receive.');
      await Api.post(`purchase-orders/${id}/receive`, { items: receiveItems });
      setReceiveModal(false); reload(); onReceive?.();
      setToast({ message: 'Goods received', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  return (
    <>
      <Modal open={!!id} onClose={onClose} title={po?.po_number || 'Purchase Order'} size="lg"
        footer={<>
          <Button variant="secondary" onClick={onClose}>Close</Button>
          {po && !['Received', 'Cancelled', 'Draft'].includes(po.status) && <Button onClick={openReceive}>Receive Goods</Button>}
        </>}>
        {loading ? <div className="flex justify-center py-16"><Spinner /></div> : po && (
          <div className="space-y-5">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-lg font-bold text-foreground">{po.po_number}</p>
                <p className="text-sm text-muted-foreground">{po.supplier_name}</p>
              </div>
              <StatusBadge status={po.status} />
            </div>
            <div className="grid grid-cols-3 gap-4 text-sm border-t border-border pt-4">
              <div><p className="text-xs text-muted-foreground">Order Date</p><p className="font-medium">{new Date(po.order_date).toLocaleDateString('en-LK')}</p></div>
              <div><p className="text-xs text-muted-foreground">Expected</p><p className="font-medium">{po.expected_date ? new Date(po.expected_date).toLocaleDateString('en-LK') : '—'}</p></div>
              <div><p className="text-xs text-muted-foreground">Total</p><p className="font-semibold">{fmt(po.total_amount)}</p></div>
            </div>
            <div className="border-t border-border pt-4">
              <p className="text-sm font-semibold text-foreground mb-2">Items</p>
              <Table
                columns={[
                  { key: 'sku', header: 'SKU', render: v => <span className="font-mono text-xs">{v}</span> },
                  { key: 'product_name', header: 'Product' },
                  { key: 'qty_ordered', header: 'Ordered', render: v => fmtN(v) },
                  { key: 'qty_received', header: 'Received', render: v => fmtN(v) },
                  { key: 'unit_cost', header: 'Unit Cost', render: v => fmt(v) },
                  { key: 'line_total', header: 'Line Total', render: v => fmt(v) },
                ]}
                data={items} emptyText="No items"
              />
            </div>
          </div>
        )}
      </Modal>
      <Modal open={receiveModal} onClose={() => setReceiveModal(false)} title="Receive Goods"
        footer={<><Button variant="secondary" onClick={() => setReceiveModal(false)}>Cancel</Button><Button onClick={submitReceive} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Confirm Receipt'}</Button></>}>
        <div className="space-y-3">
          {items.map(it => (
            <div key={it.product_id} className="flex items-center gap-3">
              <div className="flex-1">
                <p className="text-sm font-medium">{it.product_name}</p>
                <p className="text-xs text-muted-foreground">Ordered {fmtN(it.qty_ordered)} · Received {fmtN(it.qty_received)}</p>
              </div>
              <Input type="number" value={receiveQtys[it.product_id] ?? 0}
                onChange={e => setReceiveQtys(p => ({ ...p, [it.product_id]: e.target.value }))}
                className="w-28" min="0" max={+it.qty_ordered - +it.qty_received} />
            </div>
          ))}
        </div>
      </Modal>
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </>
  );
}

function PurchasingPage() {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const { data, loading, reload } = useFetch('purchase-orders', { search, status }, [search, status]);
  const list = data?.data || [];
  const { data: suppliers } = useFetch('suppliers');
  const { data: products } = useFetch('products');
  const { data: warehouses } = useFetch('warehouses');
  const suppList = suppliers?.data || [];
  const prodList = products?.data || [];
  const whList = Array.isArray(warehouses) ? warehouses : [];

  const [modal, setModal] = useState(false);
  const [form, setForm] = useState({ supplier_id: '', warehouse_id: '', order_date: new Date().toISOString().slice(0, 10), expected_date: '', notes: '' });
  const [lineItems, setLineItems] = useState([{ product_id: '', qty_ordered: 1, unit_cost: '' }]);
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState(null);
  const [detailId, setDetailId] = useState(null);

  const addLine = () => setLineItems(p => [...p, { product_id: '', qty_ordered: 1, unit_cost: '' }]);
  const removeLine = (i) => setLineItems(p => p.filter((_, idx) => idx !== i));
  const updateLine = (i, field, val) => setLineItems(p => p.map((l, idx) => idx === i ? { ...l, [field]: val } : l));

  const save = async () => {
    setBusy(true);
    try {
      const items = lineItems.filter(l => l.product_id).map(l => ({ product_id: l.product_id, qty_ordered: +l.qty_ordered, unit_cost: l.unit_cost ? +l.unit_cost : undefined }));
      if (!items.length) throw new Error('Add at least one item.');
      await Api.post('purchase-orders', { ...form, items });
      setModal(false); reload();
      setForm({ supplier_id: '', warehouse_id: '', order_date: new Date().toISOString().slice(0, 10), expected_date: '', notes: '' });
      setLineItems([{ product_id: '', qty_ordered: 1, unit_cost: '' }]);
      setToast({ message: 'Purchase order created', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  return (
    <div className="p-6 fade-in">
      <PageHeader title="Purchasing" subtitle="Purchase orders and goods receipts"
        actions={<Button onClick={() => setModal(true)}>+ New Purchase Order</Button>} />
      <Card padding={false}>
        <div className="p-4 border-b border-border flex flex-wrap gap-3">
          <SearchInput value={search} onChange={setSearch} placeholder="Search PO #, supplier…" className="w-64" />
          <Select value={status} onChange={e => setStatus(e.target.value)} className="w-40">
            <option value="">All Status</option>
            <option>Draft</option><option>Confirmed</option><option>Sent</option><option>Partially Received</option><option>Received</option><option>Cancelled</option>
          </Select>
        </div>
        <Table
          loading={loading}
          onRowClick={row => setDetailId(row.id)}
          columns={[
            { key: 'po_number', header: 'PO #', render: v => <span className="font-mono text-xs font-semibold">{v}</span> },
            { key: 'supplier_name', header: 'Supplier' },
            { key: 'order_date', header: 'Date', render: v => new Date(v).toLocaleDateString('en-LK') },
            { key: 'total_amount', header: 'Total', render: v => fmt(v) },
            { key: 'status', header: 'Status', render: v => <StatusBadge status={v} /> },
          ]}
          data={list} emptyText="No purchase orders found"
        />
      </Card>

      <Modal open={modal} onClose={() => setModal(false)} title="New Purchase Order" size="xl"
        footer={<><Button variant="secondary" onClick={() => setModal(false)}>Cancel</Button><Button onClick={save} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Create PO'}</Button></>}>
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <Select label="Supplier *" value={form.supplier_id} onChange={e => setForm(p => ({ ...p, supplier_id: e.target.value }))} required>
              <option value="">Select supplier…</option>
              {suppList.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
            </Select>
            <Select label="Warehouse" value={form.warehouse_id} onChange={e => setForm(p => ({ ...p, warehouse_id: e.target.value }))}>
              <option value="">Select warehouse…</option>
              {whList.map(w => <option key={w.id} value={w.id}>{w.name}</option>)}
            </Select>
            <Input label="Order Date *" type="date" value={form.order_date} onChange={e => setForm(p => ({ ...p, order_date: e.target.value }))} required />
            <Input label="Expected Date" type="date" value={form.expected_date} onChange={e => setForm(p => ({ ...p, expected_date: e.target.value }))} />
          </div>
          <div>
            <div className="flex items-center justify-between mb-2">
              <p className="text-sm font-semibold text-foreground">Line Items</p>
              <Button variant="outline" size="xs" onClick={addLine}>+ Add Line</Button>
            </div>
            <div className="space-y-2">
              {lineItems.map((line, i) => (
                <div key={i} className="flex items-center gap-2">
                  <Select value={line.product_id} onChange={e => updateLine(i, 'product_id', e.target.value)} className="flex-1">
                    <option value="">Select product…</option>
                    {prodList.map(p => <option key={p.id} value={p.id}>{p.name} ({p.sku})</option>)}
                  </Select>
                  <Input type="number" value={line.qty_ordered} onChange={e => updateLine(i, 'qty_ordered', e.target.value)} className="w-24" placeholder="Qty" />
                  <Input type="number" step="0.01" value={line.unit_cost} onChange={e => updateLine(i, 'unit_cost', e.target.value)} className="w-28" placeholder="Unit cost" />
                  {lineItems.length > 1 && <Button variant="ghost" size="xs" onClick={() => removeLine(i)} className="text-destructive">✕</Button>}
                </div>
              ))}
            </div>
          </div>
          <Textarea label="Notes" value={form.notes} onChange={e => setForm(p => ({ ...p, notes: e.target.value }))} />
        </div>
      </Modal>

      <PurchaseOrderDetail id={detailId} onClose={() => setDetailId(null)} onReceive={reload} />
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// INVOICES PAGE
// ═══════════════════════════════════════════════════════════
function InvoiceDetail({ id, onClose, onPaid }) {
  const { data: invoice, loading, reload } = useFetch(id ? `invoices/${id}` : null);
  const [payModal, setPayModal] = useState(false);
  const [payForm, setPayForm] = useState({ amount: '', payment_date: new Date().toISOString().slice(0, 10), method: 'Cash', reference: '' });
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState(null);

  const balance = invoice ? (+invoice.total_amount - +(invoice.paid_amount || 0)) : 0;

  const openPay = () => { setPayForm({ amount: balance > 0 ? balance.toFixed(2) : '', payment_date: new Date().toISOString().slice(0, 10), method: 'Cash', reference: '' }); setPayModal(true); };

  const recordPayment = async () => {
    setBusy(true);
    try {
      await Api.post('payments', { ...payForm, invoice_id: id, customer_id: invoice.customer_id });
      setPayModal(false); reload(); onPaid?.();
      setToast({ message: 'Payment recorded', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  return (
    <>
      <Modal open={!!id} onClose={onClose} title={invoice?.invoice_number || 'Invoice'} size="lg"
        footer={<><Button variant="secondary" onClick={onClose}>Close</Button>{invoice && balance > 0 && <Button onClick={openPay}>Record Payment</Button>}</>}>
        {loading ? <div className="flex justify-center py-16"><Spinner /></div> : invoice && (
          <div className="space-y-5">
            <div className="flex items-start justify-between">
              <div>
                <p className="text-lg font-bold text-foreground">{invoice.invoice_number}</p>
                <p className="text-sm text-muted-foreground">{invoice.customer_name}</p>
              </div>
              <StatusBadge status={invoice.status} />
            </div>
            <div className="grid grid-cols-2 gap-4 text-sm border-t border-border pt-4">
              <div><p className="text-xs text-muted-foreground">Invoice Date</p><p className="font-medium">{new Date(invoice.invoice_date).toLocaleDateString('en-LK')}</p></div>
              <div><p className="text-xs text-muted-foreground">Due Date</p><p className="font-medium">{invoice.due_date ? new Date(invoice.due_date).toLocaleDateString('en-LK') : '—'}</p></div>
              <div><p className="text-xs text-muted-foreground">Total</p><p className="font-semibold">{fmt(invoice.total_amount)}</p></div>
              <div><p className="text-xs text-muted-foreground">Paid</p><p className="font-semibold text-success">{fmt(invoice.paid_amount || 0)}</p></div>
              <div className="col-span-2"><p className="text-xs text-muted-foreground">Balance Due</p><p className={`text-lg font-bold ${balance > 0 ? 'text-destructive' : 'text-success'}`}>{fmt(balance)}</p></div>
            </div>
            {invoice.notes && <div className="border-t border-border pt-4"><p className="text-xs text-muted-foreground mb-1">Notes</p><p className="text-sm">{invoice.notes}</p></div>}
          </div>
        )}
      </Modal>
      <Modal open={payModal} onClose={() => setPayModal(false)} title="Record Payment"
        footer={<><Button variant="secondary" onClick={() => setPayModal(false)}>Cancel</Button><Button onClick={recordPayment} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Save Payment'}</Button></>}>
        <div className="space-y-4">
          <Input label="Amount *" type="number" step="0.01" value={payForm.amount} onChange={e => setPayForm(p => ({ ...p, amount: e.target.value }))} required />
          <Input label="Payment Date *" type="date" value={payForm.payment_date} onChange={e => setPayForm(p => ({ ...p, payment_date: e.target.value }))} required />
          <Select label="Method *" value={payForm.method} onChange={e => setPayForm(p => ({ ...p, method: e.target.value }))} required>
            <option>Cash</option><option>Bank Transfer</option><option>Cheque</option><option>Card</option><option>Online</option>
          </Select>
          <Input label="Reference" value={payForm.reference} onChange={e => setPayForm(p => ({ ...p, reference: e.target.value }))} placeholder="Cheque #, transaction ID, etc." />
        </div>
      </Modal>
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </>
  );
}

function InvoicesPage() {
  const [status, setStatus] = useState('');
  const { data, loading, reload } = useFetch('invoices', status ? { status } : undefined, [status]);
  const list = data?.data || [];
  const [detailId, setDetailId] = useState(null);

  return (
    <div className="p-6 fade-in">
      <PageHeader title="Invoices" subtitle="Track billing and payments" />
      <Card padding={false}>
        <div className="p-4 border-b border-border flex flex-wrap gap-3">
          <Select value={status} onChange={e => setStatus(e.target.value)} className="w-44">
            <option value="">All Status</option>
            <option>Draft</option><option>Sent</option><option>Partially Paid</option><option>Paid</option><option>Overdue</option><option>Cancelled</option>
          </Select>
        </div>
        <Table
          loading={loading}
          onRowClick={row => setDetailId(row.id)}
          columns={[
            { key: 'invoice_number', header: 'Invoice #', render: v => <span className="font-mono text-xs font-semibold">{v}</span> },
            { key: 'customer_name', header: 'Customer' },
            { key: 'invoice_date', header: 'Date', render: v => new Date(v).toLocaleDateString('en-LK') },
            { key: 'due_date', header: 'Due', render: v => v ? new Date(v).toLocaleDateString('en-LK') : '—' },
            { key: 'total_amount', header: 'Total', render: v => fmt(v) },
            { key: 'paid_amount', header: 'Paid', render: v => fmt(v || 0) },
            { key: 'status', header: 'Status', render: v => <StatusBadge status={v} /> },
          ]}
          data={list} emptyText="No invoices found"
        />
      </Card>
      <InvoiceDetail id={detailId} onClose={() => setDetailId(null)} onPaid={reload} />
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// REPORTS PAGE
// ═══════════════════════════════════════════════════════════
function ReportsPage() {
  const [range, setRange] = useState({ from: new Date(new Date().setDate(1)).toISOString().slice(0, 10), to: new Date().toISOString().slice(0, 10) });
  const { data, loading } = useFetch('reports', range, [range.from, range.to]);
  const salesSummary = data?.salesSummary || [];
  const topCustomers = data?.topCustomers || [];
  const topProducts = data?.topProducts || [];
  const monthlyTrend = data?.monthlyTrend || [];
  const ar = data?.arAgeing || {};

  const exportData = async (type) => {
    try {
      const r = await Api.get('export', { type, format: 'csv' });
      if (!r.rows) { alert('No data to export'); return; }
      const headers = r.headers || [];
      const csv = [headers.join(','), ...r.data.map(row => headers.map(h => JSON.stringify(row[h] ?? '')).join(','))].join('\n');
      const blob = new Blob([csv], { type: 'text/csv' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a'); a.href = url; a.download = `${type}.csv`; a.click();
      URL.revokeObjectURL(url);
    } catch (e) { alert(e.message); }
  };

  const totalOrders = salesSummary.reduce((s, r) => s + (+r.count || 0), 0);
  const totalRevenue = salesSummary.reduce((s, r) => s + (+r.total || 0), 0);
  const totalCollected = salesSummary.reduce((s, r) => s + (+r.paid || 0), 0);
  const totalAr = (+ar.current_30 || 0) + (+ar.days_31_60 || 0) + (+ar.days_61_90 || 0) + (+ar.over_90 || 0);

  return (
    <div className="p-6 fade-in">
      <PageHeader title="Reports" subtitle="Sales performance and receivables"
        actions={
          <div className="flex gap-2">
            <Input type="date" value={range.from} onChange={e => setRange(p => ({ ...p, from: e.target.value }))} className="w-40" />
            <Input type="date" value={range.to} onChange={e => setRange(p => ({ ...p, to: e.target.value }))} className="w-40" />
          </div>
        } />

      {loading ? <div className="flex justify-center py-32"><Spinner size="lg" /></div> : (
        <div className="space-y-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
            <KpiCard label="Total Orders" value={fmtN(totalOrders)} icon="🛒" color="primary" />
            <KpiCard label="Revenue" value={fmt(totalRevenue)} icon="💰" color="success" />
            <KpiCard label="Collected" value={fmt(totalCollected)} icon="💳" color="cyan" />
            <KpiCard label="Outstanding AR" value={fmt(totalAr)} icon="📄" color="warning" />
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <Card>
              <CardHeader><CardTitle>Monthly Revenue Trend</CardTitle></CardHeader>
              <BarChart data={monthlyTrend} labelKey="label" valueKey="revenue" format={v => `Rs. ${(+v / 1000).toFixed(1)}k`} />
            </Card>
            <Card>
              <CardHeader><CardTitle>Orders by Status</CardTitle></CardHeader>
              <BarChart data={salesSummary} labelKey="status" valueKey="count" />
            </Card>
          </div>

          <Card>
            <CardHeader>
              <CardTitle>Accounts Receivable Ageing</CardTitle>
            </CardHeader>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
              <div className="text-center p-3 rounded-lg bg-muted/50">
                <p className="text-xs text-muted-foreground mb-1">Current (0-30d)</p>
                <p className="text-lg font-bold text-foreground">{fmt(ar.current_30 || 0)}</p>
              </div>
              <div className="text-center p-3 rounded-lg bg-warning/10">
                <p className="text-xs text-muted-foreground mb-1">31-60 days</p>
                <p className="text-lg font-bold text-warning">{fmt(ar.days_31_60 || 0)}</p>
              </div>
              <div className="text-center p-3 rounded-lg bg-warning/10">
                <p className="text-xs text-muted-foreground mb-1">61-90 days</p>
                <p className="text-lg font-bold text-warning">{fmt(ar.days_61_90 || 0)}</p>
              </div>
              <div className="text-center p-3 rounded-lg bg-destructive/10">
                <p className="text-xs text-muted-foreground mb-1">Over 90 days</p>
                <p className="text-lg font-bold text-destructive">{fmt(ar.over_90 || 0)}</p>
              </div>
            </div>
          </Card>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <Card padding={false}>
              <div className="p-4 border-b border-border"><CardTitle>Top Customers</CardTitle></div>
              <Table
                columns={[
                  { key: 'name', header: 'Customer' },
                  { key: 'order_count', header: 'Orders', render: v => fmtN(v) },
                  { key: 'revenue', header: 'Revenue', render: v => fmt(v) },
                ]}
                data={topCustomers} emptyText="No data for this period"
              />
            </Card>
            <Card padding={false}>
              <div className="p-4 border-b border-border"><CardTitle>Top Products</CardTitle></div>
              <Table
                columns={[
                  { key: 'sku', header: 'SKU', render: v => <span className="font-mono text-xs">{v}</span> },
                  { key: 'name', header: 'Product' },
                  { key: 'qty_sold', header: 'Qty Sold', render: v => fmtN(v) },
                  { key: 'revenue', header: 'Revenue', render: v => fmt(v) },
                ]}
                data={topProducts} emptyText="No data for this period"
              />
            </Card>
          </div>

          <Card>
            <CardHeader><CardTitle>Export Data</CardTitle></CardHeader>
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" size="sm" onClick={() => exportData('orders')}>⬇ Orders CSV</Button>
              <Button variant="outline" size="sm" onClick={() => exportData('customers')}>⬇ Customers CSV</Button>
              <Button variant="outline" size="sm" onClick={() => exportData('products')}>⬇ Products CSV</Button>
              <Button variant="outline" size="sm" onClick={() => exportData('inventory')}>⬇ Inventory CSV</Button>
            </div>
          </Card>
        </div>
      )}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════
// SETTINGS PAGE — Users, Roles, Security (2FA)
// ═══════════════════════════════════════════════════════════
function SettingsUsersTab() {
  const { data, loading, reload } = useFetch('users');
  const { data: roles } = useFetch('roles');
  const list = Array.isArray(data) ? data : [];
  const roleList = Array.isArray(roles) ? roles : [];
  const [modal, setModal] = useState(null);
  const [form, setForm] = useState({ name: '', email: '', password: '', phone: '', role_id: '' });
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState(null);

  const save = async () => {
    setBusy(true);
    try {
      if (modal?.id) { const { password, email, ...rest } = form; await Api.put(`users/${modal.id}`, password ? { ...rest, password } : rest); }
      else            await Api.post('users', form);
      setModal(null); reload();
      setToast({ message: 'User saved', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  const remove = async (row) => {
    if (!confirm(`Deactivate user "${row.name}"?`)) return;
    try { await Api.delete(`users/${row.id}`); reload(); setToast({ message: 'User removed', type: 'success' }); }
    catch (e) { setToast({ message: e.message, type: 'danger' }); }
  };

  return (
    <div>
      <div className="flex justify-end mb-3">
        <Button size="sm" onClick={() => { setForm({ name: '', email: '', password: '', phone: '', role_id: roleList[0]?.id || '' }); setModal({}); }}>+ Add User</Button>
      </div>
      <Card padding={false}>
        <Table
          loading={loading}
          columns={[
            { key: 'name', header: 'Name' },
            { key: 'email', header: 'Email' },
            { key: 'phone', header: 'Phone', render: v => v || '—' },
            { key: 'role_name', header: 'Role', render: v => <Badge>{v}</Badge> },
            { key: 'is_active', header: 'Status', render: v => <StatusBadge status={v ? 'Active' : 'Inactive'} /> },
            { key: 'last_login_at', header: 'Last Login', render: v => v ? new Date(v).toLocaleDateString('en-LK') : 'Never' },
            { key: 'id', header: '', render: (v, row) => (
              <div className="flex gap-1">
                <Button variant="ghost" size="xs" onClick={() => { setForm({ ...row, password: '' }); setModal(row); }}>Edit</Button>
                <Button variant="ghost" size="xs" onClick={() => remove(row)} className="text-destructive">Remove</Button>
              </div>
            )},
          ]}
          data={list} emptyText="No users found"
        />
      </Card>

      <Modal open={!!modal} onClose={() => setModal(null)} title={modal?.id ? 'Edit User' : 'Add User'}
        footer={<><Button variant="secondary" onClick={() => setModal(null)}>Cancel</Button><Button onClick={save} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Save'}</Button></>}>
        <div className="space-y-4">
          <Input label="Full Name *" value={form.name || ''} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} required />
          <Input label="Email *" type="email" value={form.email || ''} onChange={e => setForm(p => ({ ...p, email: e.target.value }))} required disabled={!!modal?.id} />
          <Input label="Phone" value={form.phone || ''} onChange={e => setForm(p => ({ ...p, phone: e.target.value }))} />
          <Select label="Role *" value={form.role_id || ''} onChange={e => setForm(p => ({ ...p, role_id: e.target.value }))} required>
            <option value="">Select role…</option>
            {roleList.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
          </Select>
          <Input label={modal?.id ? 'New Password (leave blank to keep current)' : 'Password *'} type="password" value={form.password || ''} onChange={e => setForm(p => ({ ...p, password: e.target.value }))} required={!modal?.id} placeholder="8+ chars, upper, lower, number" />
        </div>
      </Modal>
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </div>
  );
}

function SettingsRolesTab() {
  const { data, loading } = useFetch('roles');
  const list = Array.isArray(data) ? data : [];
  return (
    <Card padding={false}>
      <Table
        loading={loading}
        columns={[
          { key: 'name', header: 'Role' },
          { key: 'is_system', header: 'Type', render: v => <Badge variant={v ? 'default' : 'muted'}>{v ? 'System' : 'Custom'}</Badge> },
          { key: 'permissions', header: 'Permissions', render: v => {
            const p = typeof v === 'string' ? JSON.parse(v || '{}') : (v || {});
            if (p.all) return <Badge variant="success">Full Access</Badge>;
            const mods = Object.keys(p).length;
            return <span className="text-xs text-muted-foreground">{mods} module{mods !== 1 ? 's' : ''} configured</span>;
          }},
        ]}
        data={list} emptyText="No roles found"
      />
    </Card>
  );
}

function SettingsSecurityTab({ user, onUserChange }) {
  const [step, setStep] = useState('idle'); // idle | setup | enabled
  const [secret, setSecret] = useState('');
  const [qrUri, setQrUri] = useState('');
  const [code, setCode] = useState('');
  const [recoveryCodes, setRecoveryCodes] = useState(null);
  const [disablePw, setDisablePw] = useState('');
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState(null);
  const totpEnabled = !!user?.totp_enabled;

  const startSetup = async () => {
    setBusy(true);
    try {
      const r = await Api.post('two-factor/setup', {});
      setSecret(r.secret); setQrUri(r.qr_uri); setStep('setup');
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  const confirmEnable = async () => {
    setBusy(true);
    try {
      const r = await Api.post('two-factor/enable', { code });
      setRecoveryCodes(r.recovery_codes); setStep('enabled');
      await onUserChange?.();
      setToast({ message: '2FA enabled', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  const disable = async () => {
    setBusy(true);
    try {
      await Api.post('two-factor/disable', { current_password: disablePw });
      setStep('idle'); setDisablePw('');
      await onUserChange?.();
      setToast({ message: '2FA disabled', type: 'success' });
    } catch (e) { setToast({ message: e.message, type: 'danger' }); }
    finally { setBusy(false); }
  };

  return (
    <Card className="max-w-xl">
      <CardHeader><CardTitle>Two-Factor Authentication</CardTitle></CardHeader>
      {totpEnabled && step !== 'enabled' ? (
        <div className="space-y-4">
          <div className="flex items-center gap-2 text-sm text-success"><Badge variant="success">Enabled</Badge> Your account is protected with 2FA.</div>
          <div className="border-t border-border pt-4">
            <p className="text-sm font-medium text-foreground mb-2">Disable 2FA</p>
            <div className="flex gap-2">
              <Input type="password" placeholder="Current password" value={disablePw} onChange={e => setDisablePw(e.target.value)} className="flex-1" />
              <Button variant="danger" onClick={disable} disabled={busy || !disablePw}>{busy ? <Spinner size="sm" /> : 'Disable'}</Button>
            </div>
          </div>
        </div>
      ) : step === 'idle' ? (
        <div className="space-y-3">
          <p className="text-sm text-muted-foreground">Add an extra layer of security — a 6-digit code from an authenticator app will be required at login.</p>
          <Button onClick={startSetup} disabled={busy}>{busy ? <Spinner size="sm" /> : 'Set Up 2FA'}</Button>
        </div>
      ) : step === 'setup' ? (
        <div className="space-y-4">
          <p className="text-sm text-muted-foreground">Scan this with your authenticator app (Google Authenticator, Authy, 1Password), or enter the key manually:</p>
          <div className="bg-muted rounded-md p-3 font-mono text-sm break-all">{secret}</div>
          <div className="bg-muted rounded-md p-3 text-xs break-all text-muted-foreground">{qrUri}</div>
          <Input label="Enter the 6-digit code to confirm" value={code} onChange={e => setCode(e.target.value)} placeholder="123456" autoFocus />
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => setStep('idle')}>Cancel</Button>
            <Button onClick={confirmEnable} disabled={busy || !code}>{busy ? <Spinner size="sm" /> : 'Confirm & Enable'}</Button>
          </div>
        </div>
      ) : (
        <div className="space-y-4">
          <div className="flex items-center gap-2 text-sm text-success"><Badge variant="success">Enabled</Badge> 2FA is now active.</div>
          {recoveryCodes && (
            <div className="border border-warning/40 bg-warning/10 rounded-md p-3">
              <p className="text-xs font-semibold text-warning mb-2">Save these recovery codes — shown only once:</p>
              <div className="grid grid-cols-2 gap-1 font-mono text-xs">
                {recoveryCodes.map(c => <span key={c}>{c}</span>)}
              </div>
            </div>
          )}
        </div>
      )}
      {toast && <Toast message={toast.message} type={toast.type} onClose={() => setToast(null)} />}
    </Card>
  );
}

function SettingsPage({ user, onUserChange }) {
  const [tab, setTab] = useState('users');
  return (
    <div className="p-6 fade-in">
      <PageHeader title="Settings" subtitle="Users, roles and security" />
      <Tabs
        tabs={[
          { key: 'users', label: 'Users', icon: '👥' },
          { key: 'roles', label: 'Roles', icon: '🔑' },
          { key: 'security', label: 'Security', icon: '🔒' },
        ]}
        active={tab} onChange={setTab} className="mb-4 w-fit"
      />
      {tab === 'users'    && <SettingsUsersTab />}
      {tab === 'roles'    && <SettingsRolesTab />}
      {tab === 'security' && <SettingsSecurityTab user={user} onUserChange={onUserChange} />}
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
// MAIN APP
// ═══════════════════════════════════════════════════════════
export default function App() {
  const [dark, toggleTheme] = useTheme();
  const { user, loading, login, verifyTotp, logout, refreshUser } = useAuth();
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

  if (!user) return <LoginPage onLogin={login} onVerifyTotp={verifyTotp} />;

  const renderPage = () => {
    switch (page) {
      case 'dashboard':    return <DashboardPage />;
      case 'orders':       return <OrdersPage />;
      case 'reps':         return <RepsPage />;
      case 'areas':        return <AreasPage />;
      case 'production':   return <ProductionPage />;
      case 'distributors': return <DistributorsPage />;
      case 'customers':    return <CustomersPage />;
      case 'products':     return <ProductsPage />;
      case 'inventory':    return <InventoryPage />;
      case 'suppliers':    return <SuppliersPage />;
      case 'purchasing':   return <PurchasingPage />;
      case 'invoices':     return <InvoicesPage />;
      case 'reports':      return <ReportsPage />;
      case 'settings':     return <SettingsPage user={user} onUserChange={refreshUser} />;
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
