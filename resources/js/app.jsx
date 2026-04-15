import './bootstrap';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Link, NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { useEffect, useMemo, useRef, useState } from 'react';

const getCsrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const setCsrf = (token) => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && token) meta.setAttribute('content', token);
};

async function parseResponse(response) {
    const text = await response.text();
    try {
        return text ? JSON.parse(text) : {};
    } catch {
        return { message: text || `HTTP ${response.status}` };
    }
}

async function refreshCsrfToken() {
    const response = await fetch('/auth/csrf-token', {
        method: 'GET',
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    const data = await parseResponse(response);
    if (data?.csrf_token) setCsrf(data.csrf_token);
}

async function api(path, method = 'GET', body = null, retried = false) {
    const response = await fetch(path, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : null,
    });
    const data = await parseResponse(response);

    if (response.status === 419 && !retried) {
        await refreshCsrfToken();
        return api(path, method, body, true);
    }

    if (!response.ok) throw data;
    return data;
}

function AppShell() {
    const [authUser, setAuthUser] = useState(null);
    const [authReady, setAuthReady] = useState(false);
    const menuItems = useMemo(() => [{ to: '/admin', label: 'Admin' }, { to: '/report', label: 'Reports' }], []);

    const loadMe = async () => {
        try {
            const res = await fetch('/auth/me', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) {
                setAuthUser(null);
            } else {
                const data = await res.json();
                setAuthUser(data.user);
            }
        } finally {
            setAuthReady(true);
        }
    };

    useEffect(() => { loadMe(); }, []);

    const logout = async () => {
        const data = await api('/auth/logout', 'POST');
        if (data?.csrf_token) setCsrf(data.csrf_token);
        setAuthUser(null);
        window.location.assign('/login');
    };

    if (!authReady) return <div className="loadingScreen">Loading app...</div>;

    if (!authUser) {
        return (
            <BrowserRouter>
                <main className="mainPane">
                    <Routes>
                        <Route path="/" element={<UserFlowPage />} />
                        <Route path="/login" element={<LoginPage onLogin={setAuthUser} />} />
                        <Route path="/admin" element={<RequireAuth user={authUser}><AdminPage user={authUser} /></RequireAuth>} />
                        <Route path="/report" element={<RequireAuth user={authUser}><ReportPage /></RequireAuth>} />
                        <Route path="*" element={<Navigate to="/" replace />} />
                    </Routes>
                </main>
            </BrowserRouter>
        );
    }

    return (
        <BrowserRouter>
            <div className="shell">
                <aside className="sidebar">
                    <h2>QA Flow Studio</h2>
                    <nav>
                        {menuItems.map((item) => (
                            <NavLink key={item.to} to={item.to} end={item.to === '/'}>{item.label}</NavLink>
                        ))}
                    </nav>
                    <div className="authBox">
                        <p>{authUser.name} ({authUser.role})</p>
                        <button onClick={logout}>Logout</button>
                    </div>
                </aside>
                <main className="mainPane">
                    <Routes>
                        <Route path="/" element={<UserFlowPage />} />
                        <Route path="/login" element={<Navigate to="/admin" replace />} />
                        <Route path="/admin" element={<RequireAuth user={authUser}><AdminPage user={authUser} /></RequireAuth>} />
                        <Route path="/report" element={<RequireAuth user={authUser}><ReportPage /></RequireAuth>} />
                        <Route path="*" element={<Navigate to="/" replace />} />
                    </Routes>
                </main>
            </div>
        </BrowserRouter>
    );
}

function RequireAuth({ user, children }) {
    if (!user || user.role !== 'admin') {
        const redirectPath = typeof window !== 'undefined' ? window.location.pathname : '/admin';
        return <Navigate to={`/login?redirect=${encodeURIComponent(redirectPath)}`} replace />;
    }
    return children;
}

function LoginPage({ onLogin }) {
    const [form, setForm] = useState({ email: '', password: '' });
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    const login = async (e) => {
        e.preventDefault();
        setLoading(true);
        setError('');
        try {
            const data = await api('/auth/login', 'POST', form);
            if (data?.csrf_token) setCsrf(data.csrf_token);
            onLogin(data.user);
            const redirectTarget = new URLSearchParams(window.location.search).get('redirect');
            const allowedRedirects = new Set(['/admin', '/report']);
            let nextPath = '/admin';
            if (redirectTarget) {
                try {
                    const resolved = new URL(redirectTarget, window.location.origin);
                    if (resolved.origin === window.location.origin && allowedRedirects.has(resolved.pathname)) {
                        nextPath = `${resolved.pathname}${resolved.search}${resolved.hash}`;
                    }
                } catch {
                    nextPath = '/admin';
                }
            }
            window.location.assign(nextPath);
        } catch (err) {
            setError(err.message || 'Login failed.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <section className="panel narrow">
            <div className="cardBlock">
                <h1>Admin Login</h1>
                <p className="muted">Sign in with your admin account credentials.</p>
                <form className="form" onSubmit={login}>
                    <input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required />
                    <input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required />
                    <button disabled={loading}>{loading ? 'Signing in...' : 'Login'}</button>
                </form>
                {error && <p className="error">{error}</p>}
                <p><Link to="/">Back to flow</Link></p>
            </div>
        </section>
    );
}

function UserFlowPage() {
    const [lead, setLead] = useState({ name: '', email: '', phone: '' });
    const [sessionId, setSessionId] = useState(null);
    const [question, setQuestion] = useState(null);
    const [answerOptionId, setAnswerOptionId] = useState('');
    const [answerOptionIds, setAnswerOptionIds] = useState([]);
    const [result, setResult] = useState('');
    const [trail, setTrail] = useState([]);
    const [history, setHistory] = useState([]);
    const [error, setError] = useState('');
    const [leadSaved, setLeadSaved] = useState(false);
    const [savingLead, setSavingLead] = useState(false);

    const reset = () => {
        setSessionId(null);
        setQuestion(null);
        setAnswerOptionId('');
        setAnswerOptionIds([]);
        setResult('');
        setTrail([]);
        setHistory([]);
        setError('');
        setLeadSaved(false);
        setSavingLead(false);
        setLead({ name: '', email: '', phone: '' });
    };

    const start = async () => {
        setError('');
        try {
            const data = await api('/api/flow/start', 'POST', {});
            setSessionId(data.session_id);
            setQuestion(data.question);
            setResult('');
            setTrail([]);
            setHistory([]);
            setAnswerOptionId('');
            setAnswerOptionIds([]);
            setLeadSaved(false);
        } catch (err) {
            setError(err.message || 'Could not start.');
        }
    };

    const goBack = async () => {
        if (!sessionId) return;
        setError('');
        const prev = trail[trail.length - 1];
        const keepQuestionIds = trail.slice(0, -1).map((t) => t.question.id);
        try {
            await api('/api/flow/prune', 'POST', { session_id: sessionId, keep_question_ids: keepQuestionIds });
        } catch (err) {
            setError(err.message || 'Could not go back.');
            return;
        }

        setTrail((t) => t.slice(0, -1));
        setHistory((h) => h.slice(0, -1));
        setResult('');
        setLeadSaved(false);
        setQuestion(prev.question);
        if ((prev.question.selection_type || 'single') === 'multi') {
            setAnswerOptionIds(prev.answer_option_ids || []);
            setAnswerOptionId('');
        } else {
            setAnswerOptionId(prev.answer_option_id || '');
            setAnswerOptionIds([]);
        }
    };

    const submitAnswer = async (e) => {
        e.preventDefault();
        if (!question) return;
        if ((question.selection_type || 'single') === 'multi') {
            if (answerOptionIds.length === 0) return;
        } else if (!answerOptionId) {
            return;
        }
        setError('');
        try {
            const isMulti = (question.selection_type || 'single') === 'multi';
            const pickedTexts = isMulti
                ? question.options.filter((o) => answerOptionIds.includes(String(o.id))).map((o) => o.option_text)
                : [question.options.find((o) => String(o.id) === String(answerOptionId))?.option_text].filter(Boolean);

            const payload = isMulti
                ? { session_id: sessionId, question_id: question.id, answer_option_ids: answerOptionIds.map((id) => Number(id)) }
                : { session_id: sessionId, question_id: question.id, answer_option_id: Number(answerOptionId) };

            const data = await api('/api/flow/answer', 'POST', payload);
            setTrail((prev) => [...prev, {
                question,
                answer_option_id: isMulti ? null : String(answerOptionId),
                answer_option_ids: isMulti ? [...answerOptionIds] : [],
            }]);
            setHistory((prev) => [...prev, { q: question.question_text, a: pickedTexts.length ? pickedTexts.join(', ') : '-' }]);
            if (data.done) {
                setResult(data.result);
                setQuestion(null);
            } else {
                setQuestion(data.question);
                setAnswerOptionId('');
                setAnswerOptionIds([]);
            }
        } catch (err) {
            setError(err.message || 'Could not submit answer.');
        }
    };

    const submitLead = async (e) => {
        e.preventDefault();
        if (!sessionId) return;
        setError('');
        setSavingLead(true);
        try {
            await api('/api/flow/lead', 'POST', { session_id: sessionId, ...lead });
            setLeadSaved(true);
        } catch (err) {
            setError(err.message || 'Could not save details.');
        } finally {
            setSavingLead(false);
        }
    };

    return (
        <section className="panel">
            <header className="panelHead">
                <h1>Intelligent Question Flow</h1>
                <p>Answer the questions first. At the end, share your name, email and phone.</p>
            </header>

            {!sessionId && (
                <div className="cardBlock">
                    <h3>Start</h3>
                    <p className="muted">Click start to begin the flow.</p>
                    <button onClick={start}>Start</button>
                </div>
            )}

            {question && (
                <form className="form cardBlock" onSubmit={submitAnswer}>
                    <h3>{question.question_text}</h3>
                    {(question.selection_type || 'single') === 'multi' ? (
                        question.options.map((opt) => (
                            <label className="radioRow" key={opt.id}>
                                <input
                                    type="checkbox"
                                    value={opt.id}
                                    checked={answerOptionIds.includes(String(opt.id))}
                                    onChange={(e) => {
                                        const id = String(opt.id);
                                        setAnswerOptionIds((prev) => (e.target.checked ? [...prev, id] : prev.filter((x) => x !== id)));
                                    }}
                                />
                                <span>{opt.option_text}</span>
                            </label>
                        ))
                    ) : (
                        question.options.map((opt) => (
                            <label className="radioRow" key={opt.id}>
                                <input type="radio" name="answer" value={opt.id} checked={String(answerOptionId) === String(opt.id)} onChange={(e) => setAnswerOptionId(e.target.value)} required />
                                <span>{opt.option_text}</span>
                            </label>
                        ))
                    )}
                    <div className="btnRow">
                        <button type="button" className="btnGhost" onClick={goBack} disabled={trail.length === 0}>Previous</button>
                        <button>Next</button>
                    </div>
                </form>
            )}

            {result && <div className="resultCard"><h3>Result</h3><p>{result}</p></div>}

            {result && !leadSaved && (
                <form className="form cardBlock" onSubmit={submitLead}>
                    <h3>Final Step: Your Details</h3>
                    <input placeholder="Name" value={lead.name} onChange={(e) => setLead({ ...lead, name: e.target.value })} required />
                    <input placeholder="Email" type="email" value={lead.email} onChange={(e) => setLead({ ...lead, email: e.target.value })} required />
                    <input placeholder="Phone" value={lead.phone} onChange={(e) => setLead({ ...lead, phone: e.target.value })} required />
                    <div className="btnRow">
                        <button type="button" className="btnGhost" onClick={goBack} disabled={trail.length === 0}>Previous</button>
                        <button disabled={savingLead}>{savingLead ? 'Saving...' : 'Submit'}</button>
                    </div>
                </form>
            )}

            {result && leadSaved && (
                <div className="cardBlock">
                    <h3>Thank you</h3>
                    <p className="muted">Your details were submitted.</p>
                    <button type="button" className="btnGhost" onClick={reset}>Start again</button>
                </div>
            )}

            {history.length > 0 && (
                <div className="cardBlock">
                    <h3>Answer History</h3>
                    <ul className="trail">{history.map((h, idx) => <li key={idx}><strong>{h.q}</strong><span>{h.a}</span></li>)}</ul>
                </div>
            )}

            {error && <p className="error">{error}</p>}
        </section>
    );
}

function AdminPage({ user }) {
    const canEdit = user.role === 'admin';

    const [data, setData] = useState({ questions: [], options: [], routes: [], sessions: [] });
    const [flows, setFlows] = useState([]);
    const [activeFlowId, setActiveFlowId] = useState(null);
    const [selectedFlowId, setSelectedFlowId] = useState(null);
    const [newFlowTitle, setNewFlowTitle] = useState('');
    const [qForm, setQForm] = useState({ question_text: '', selection_type: 'single', sort_order: 0, is_start: false });
    const [oForm, setOForm] = useState({ question_id: '', option_text: '', sort_order: 0 });
    const [rForm, setRForm] = useState({ question_id: '', answer_option_id: '', next_question_id: '', is_terminal: false, terminal_message: '' });
    const [msg, setMsg] = useState('');
    const [confirm, setConfirm] = useState(null);
    const [editModal, setEditModal] = useState(null);
    const [showVisual, setShowVisual] = useState(false);

    const [canvasQuestions, setCanvasQuestions] = useState([]);
    const dragRef = useRef({ id: null, offsetX: 0, offsetY: 0 });

    const load = async (qid = null) => {
        const qs = qid ? `?questionnaire_id=${encodeURIComponent(String(qid))}` : '';
        const res = await api(`/admin-api/flow${qs}`);
        setData(res);
        setFlows(res.questionnaires || []);
        setActiveFlowId(res.active_questionnaire_id || null);
        setSelectedFlowId(res.questionnaire?.id || qid || null);
        setCanvasQuestions(res.questions);
    };

    useEffect(() => { load(); }, []);

    const optionsByQ = useMemo(
        () => data.options.filter((o) => String(o.question_id) === String(rForm.question_id)),
        [data.options, rForm.question_id]
    );

    const questionTextById = useMemo(() => {
        const map = {};
        for (const q of data.questions) map[String(q.id)] = q.question_text;
        return map;
    }, [data.questions]);

    const optionTextById = useMemo(() => {
        const map = {};
        for (const o of data.options) map[String(o.id)] = o.option_text;
        return map;
    }, [data.options]);

    const startDrag = (event, question) => {
        if (!canEdit) return;
        dragRef.current = {
            id: question.id,
            offsetX: event.clientX - question.pos_x,
            offsetY: event.clientY - question.pos_y,
        };
    };

    useEffect(() => {
        const onMove = (event) => {
            const id = dragRef.current.id;
            if (!id) return;
            const x = Math.max(0, event.clientX - dragRef.current.offsetX);
            const y = Math.max(0, event.clientY - dragRef.current.offsetY);
            setCanvasQuestions((prev) => prev.map((q) => (q.id === id ? { ...q, pos_x: x, pos_y: y } : q)));
        };

        const onUp = async () => {
            const id = dragRef.current.id;
            if (!id) return;
            const moved = canvasQuestions.find((q) => q.id === id);
            dragRef.current.id = null;
            if (moved) {
                await api(`/admin-api/questions/${id}/position`, 'PUT', { pos_x: Math.round(moved.pos_x), pos_y: Math.round(moved.pos_y) });
                load();
            }
        };

        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onUp);
        return () => {
            window.removeEventListener('mousemove', onMove);
            window.removeEventListener('mouseup', onUp);
        };
    }, [canvasQuestions]);

    const submitQuestion = async (e) => {
        e.preventDefault();
        await api('/admin-api/questions', 'POST', { ...qForm, questionnaire_id: selectedFlowId, is_start: !!qForm.is_start });
        setQForm({ question_text: '', selection_type: 'single', sort_order: 0, is_start: false });
        setMsg('Question added.');
        load(selectedFlowId);
    };

    const submitOption = async (e) => {
        e.preventDefault();
        await api('/admin-api/options', 'POST', { ...oForm, question_id: Number(oForm.question_id) });
        setOForm({ question_id: '', option_text: '', sort_order: 0 });
        setMsg('Option added.');
        load(selectedFlowId);
    };

    const submitRoute = async (e) => {
        e.preventDefault();
        await api('/admin-api/routes', 'POST', {
            question_id: Number(rForm.question_id),
            answer_option_id: Number(rForm.answer_option_id),
            next_question_id: rForm.is_terminal || !rForm.next_question_id ? null : Number(rForm.next_question_id),
            is_terminal: !!rForm.is_terminal,
            terminal_message: rForm.is_terminal ? rForm.terminal_message : null,
        });
        setRForm({ question_id: '', answer_option_id: '', next_question_id: '', is_terminal: false, terminal_message: '' });
        setMsg('Route saved.');
        load(selectedFlowId);
    };

    const openDelete = (type, item) => {
        setConfirm({
            title: `Delete ${type}`,
            message: `Are you sure you want to delete this ${type}?`,
            action: async () => {
                const base = type === 'question' ? 'questions' : type === 'option' ? 'options' : 'routes';
                await api(`/admin-api/${base}/${item.id}`, 'DELETE');
                setConfirm(null);
                load(selectedFlowId);
            },
        });
    };

    const openEdit = (type, item) => {
        if (type === 'question') {
            setEditModal({
                type,
                id: item.id,
                payload: {
                    question_text: item.question_text,
                    selection_type: item.selection_type || 'single',
                    sort_order: item.sort_order,
                    is_start: !!item.is_start,
                    is_active: !!item.is_active,
                },
            });
        } else if (type === 'option') {
            setEditModal({ type, id: item.id, payload: { question_id: item.question_id, option_text: item.option_text, sort_order: item.sort_order } });
        } else {
            setEditModal({ type, id: item.id, payload: { next_question_id: item.next_question_id || '', is_terminal: !!item.is_terminal, terminal_message: item.terminal_message || '' } });
        }
    };

    const saveEdit = async () => {
        if (!editModal) return;
        if (editModal.type === 'question') {
            await api(`/admin-api/questions/${editModal.id}`, 'PUT', editModal.payload);
        } else if (editModal.type === 'option') {
            await api(`/admin-api/options/${editModal.id}`, 'PUT', {
                question_id: Number(editModal.payload.question_id),
                option_text: editModal.payload.option_text,
                sort_order: editModal.payload.sort_order,
            });
        } else {
            await api(`/admin-api/routes/${editModal.id}`, 'PUT', {
                ...editModal.payload,
                next_question_id: editModal.payload.is_terminal || !editModal.payload.next_question_id ? null : Number(editModal.payload.next_question_id),
            });
        }
        setEditModal(null);
        load(selectedFlowId);
    };

    const createFlow = async (e) => {
        e.preventDefault();
        if (!newFlowTitle.trim()) return;
        await api('/admin-api/questionnaires', 'POST', { title: newFlowTitle.trim() });
        setNewFlowTitle('');
        setMsg('Flow created.');
        load(selectedFlowId);
    };

    const activateFlow = async () => {
        if (!selectedFlowId) return;
        await api(`/admin-api/questionnaires/${selectedFlowId}/activate`, 'PUT');
        setMsg('Flow activated.');
        load(selectedFlowId);
    };

    const lineData = data.routes
        .map((route) => {
            const source = canvasQuestions.find((q) => q.id === route.question_id);
            const target = canvasQuestions.find((q) => q.id === route.next_question_id);
            if (!source || !target || route.is_terminal) return null;
            return {
                id: route.id,
                x1: source.pos_x + 140,
                y1: source.pos_y + 40,
                x2: target.pos_x + 10,
                y2: target.pos_y + 40,
            };
        })
        .filter(Boolean);

    return (
        <section className="panel">
            <header className="panelHead">
                <h1>Simple Admin Builder</h1>
                <p>Use simple forms and tables first. Visual map is optional below.</p>
            </header>

            {msg && <p className="status">{msg}</p>}

            <div className="cardBlock">
                <h3>Flows</h3>
                <div className="adminGrid">
                    <div className="form">
                        <div className="fieldGroup">
                            <label className="fieldLabel">Select Flow</label>
                            <select value={selectedFlowId || ''} onChange={(e) => load(Number(e.target.value))}>
                                {flows.length === 0 && <option value="">No flows</option>}
                                {flows.map((f) => (
                                    <option key={f.id} value={f.id}>
                                        {f.title}{Number(f.id) === Number(activeFlowId) ? ' (Active)' : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <button type="button" className="btnGhost" onClick={activateFlow} disabled={!selectedFlowId || Number(selectedFlowId) === Number(activeFlowId)}>
                            Set as active (front user)
                        </button>
                    </div>
                    <form className="form" onSubmit={createFlow}>
                        <div className="fieldGroup">
                            <label className="fieldLabel">Create New Flow</label>
                            <input placeholder="Flow title" value={newFlowTitle} onChange={(e) => setNewFlowTitle(e.target.value)} />
                        </div>
                        <button>Create</button>
                    </form>
                </div>
            </div>

            <div className="cardBlock">
                <div className="visualHead">
                    <h3>Visual Flow Map</h3>
                    <button type="button" className="btnGhost" onClick={() => setShowVisual((v) => !v)}>
                        {showVisual ? 'Hide Visual Map' : 'Show Visual Map'}
                    </button>
                </div>
                <p className="muted">Drag and drop changes only node position for easy viewing. It does not auto-change route connections.</p>
                {showVisual && (
                    <div className="canvasWrap">
                        <svg className="canvasLines">
                            {lineData.map((line) => (
                                <line key={line.id} x1={line.x1} y1={line.y1} x2={line.x2} y2={line.y2} stroke="#2563eb" strokeWidth="2" />
                            ))}
                        </svg>
                        {canvasQuestions.map((q) => (
                            <div
                                key={q.id}
                                className="nodeCard"
                                style={{ left: q.pos_x, top: q.pos_y }}
                                onMouseDown={(e) => startDrag(e, q)}
                            >
                                <strong>Q{q.id}</strong>
                                <p>{q.question_text}</p>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {canEdit && (
                <>
                    <div className="adminGrid">
                        <form className="form cardBlock" onSubmit={submitQuestion}>
                            <h3>Add Question</h3>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Question Text</label>
                                <input required placeholder="Question text" value={qForm.question_text} onChange={(e) => setQForm({ ...qForm, question_text: e.target.value })} />
                            </div>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Answer Type</label>
                                <select value={qForm.selection_type} onChange={(e) => setQForm({ ...qForm, selection_type: e.target.value })}>
                                    <option value="single">Single (radio)</option>
                                    <option value="multi">Multiple (checkbox)</option>
                                </select>
                            </div>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Sort Order</label>
                                <input type="number" value={qForm.sort_order} onChange={(e) => setQForm({ ...qForm, sort_order: Number(e.target.value) })} />
                            </div>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Start Question</label>
                                <label className="radioRow"><input type="checkbox" checked={qForm.is_start} onChange={(e) => setQForm({ ...qForm, is_start: e.target.checked })} /><span>Yes</span></label>
                            </div>
                            <button>Add</button>
                        </form>

                        <form className="form cardBlock" onSubmit={submitOption}>
                            <h3>Add Option</h3>
                            <select required value={oForm.question_id} onChange={(e) => setOForm({ ...oForm, question_id: e.target.value })}>
                                <option value="">Question</option>
                                {data.questions.map((q) => <option key={q.id} value={q.id}>{q.question_text}</option>)}
                            </select>
                            <input required placeholder="Option text" value={oForm.option_text} onChange={(e) => setOForm({ ...oForm, option_text: e.target.value })} />
                            <input type="number" value={oForm.sort_order} onChange={(e) => setOForm({ ...oForm, sort_order: Number(e.target.value) })} />
                            <button>Add</button>
                        </form>
                    </div>

                    <form className="form cardBlock" onSubmit={submitRoute}>
                        <h3>Route Mapping</h3>
                        <select required value={rForm.question_id} onChange={(e) => setRForm({ ...rForm, question_id: e.target.value, answer_option_id: '' })}>
                            <option value="">Question</option>
                            {data.questions.map((q) => <option key={q.id} value={q.id}>{q.question_text}</option>)}
                        </select>
                        <select required value={rForm.answer_option_id} onChange={(e) => setRForm({ ...rForm, answer_option_id: e.target.value })}>
                            <option value="">Answer option</option>
                            {optionsByQ.map((o) => <option key={o.id} value={o.id}>{o.option_text}</option>)}
                        </select>
                        <select disabled={rForm.is_terminal} value={rForm.next_question_id} onChange={(e) => setRForm({ ...rForm, next_question_id: e.target.value })}>
                            <option value="">Next question</option>
                            {data.questions.map((q) => <option key={q.id} value={q.id}>{q.question_text}</option>)}
                        </select>
                        <label className="radioRow"><input type="checkbox" checked={rForm.is_terminal} onChange={(e) => setRForm({ ...rForm, is_terminal: e.target.checked })} /><span>Terminal route</span></label>
                        <input disabled={!rForm.is_terminal} placeholder="Terminal message" value={rForm.terminal_message} onChange={(e) => setRForm({ ...rForm, terminal_message: e.target.value })} />
                        <button>Save route</button>
                    </form>
                </>
            )}

            <div className="cardBlock">
                <h3>Questions</h3>
                <ActionTable
                    headers={['ID', 'Question', 'Type', 'Start', 'Sort', 'Actions']}
                    rows={data.questions.map((q) => [
                        q.id,
                        q.question_text,
                        q.selection_type === 'multi' ? 'Multiple' : 'Single',
                        q.is_start ? 'Yes' : 'No',
                        q.sort_order,
                        <RowActions key={`q-${q.id}`} canEdit={canEdit} onEdit={() => openEdit('question', q)} onDelete={() => openDelete('question', q)} />,
                    ])}
                />
            </div>

            <div className="cardBlock">
                <h3>Options</h3>
                <ActionTable
                    headers={['ID', 'Question', 'Option', 'Sort', 'Actions']}
                    rows={data.options.map((o) => [
                        o.id,
                        questionTextById[String(o.question_id)] || '-',
                        o.option_text,
                        o.sort_order,
                        <RowActions key={`o-${o.id}`} canEdit={canEdit} onEdit={() => openEdit('option', o)} onDelete={() => openDelete('option', o)} />,
                    ])}
                />
            </div>

            <div className="cardBlock">
                <h3>Routes</h3>
                <ActionTable
                    headers={['ID', 'Question', 'Answer', 'Next', 'Terminal', 'Actions']}
                    rows={data.routes.map((r) => [
                        r.id,
                        questionTextById[String(r.question_id)] || '-',
                        optionTextById[String(r.answer_option_id)] || '-',
                        r.is_terminal ? '-' : (questionTextById[String(r.next_question_id)] || '-'),
                        r.is_terminal ? 'Yes' : 'No',
                        <RowActions key={`r-${r.id}`} canEdit={canEdit} onEdit={() => openEdit('route', r)} onDelete={() => openDelete('route', r)} />,
                    ])}
                />
            </div>

            {confirm && (
                <ConfirmModal
                    title={confirm.title}
                    message={confirm.message}
                    onCancel={() => setConfirm(null)}
                    onConfirm={confirm.action}
                />
            )}

            {editModal && (
                <EditModal
                    modal={editModal}
                    questions={data.questions}
                    onChange={(payload) => setEditModal({ ...editModal, payload })}
                    onCancel={() => setEditModal(null)}
                    onSave={saveEdit}
                />
            )}
        </section>
    );
}

function RowActions({ canEdit, onEdit, onDelete }) {
    if (!canEdit) return <span className="muted">Read only</span>;
    return (
        <div className="rowActions">
            <button type="button" className="btnGhost" onClick={onEdit}>Edit</button>
            <button type="button" className="btnDanger" onClick={onDelete}>Delete</button>
        </div>
    );
}

function ConfirmModal({ title, message, onCancel, onConfirm }) {
    return (
        <div className="modalOverlay">
            <div className="modalBox">
                <h3>{title}</h3>
                <p>{message}</p>
                <div className="modalActions">
                    <button type="button" className="btnGhost" onClick={onCancel}>Cancel</button>
                    <button type="button" className="btnDanger" onClick={onConfirm}>Confirm</button>
                </div>
            </div>
        </div>
    );
}

function EditModal({ modal, questions, onChange, onCancel, onSave }) {
    const p = modal.payload;
    return (
        <div className="modalOverlay">
            <div className="modalBox">
                <h3>Edit {modal.type}</h3>
                <div className="form">
                    {modal.type === 'question' && (
                        <>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Question Text</label>
                                <input value={p.question_text} onChange={(e) => onChange({ ...p, question_text: e.target.value })} />
                            </div>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Answer Type</label>
                                <select value={p.selection_type || 'single'} onChange={(e) => onChange({ ...p, selection_type: e.target.value })}>
                                    <option value="single">Single (radio)</option>
                                    <option value="multi">Multiple (checkbox)</option>
                                </select>
                            </div>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Sort Order</label>
                                <input type="number" value={p.sort_order} onChange={(e) => onChange({ ...p, sort_order: Number(e.target.value) })} />
                            </div>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Start Question</label>
                                <label className="radioRow"><input type="checkbox" checked={!!p.is_start} onChange={(e) => onChange({ ...p, is_start: e.target.checked })} /><span>Yes</span></label>
                            </div>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Active</label>
                                <label className="radioRow"><input type="checkbox" checked={!!p.is_active} onChange={(e) => onChange({ ...p, is_active: e.target.checked })} /><span>Yes</span></label>
                            </div>
                        </>
                    )}
                    {modal.type === 'option' && (
                        <>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Connected Question</label>
                                <select value={p.question_id} onChange={(e) => onChange({ ...p, question_id: e.target.value })}>
                                    {questions.map((q) => <option key={q.id} value={q.id}>{q.question_text}</option>)}
                                </select>
                            </div>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Option Text</label>
                                <input value={p.option_text} onChange={(e) => onChange({ ...p, option_text: e.target.value })} />
                            </div>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Sort Order</label>
                                <input type="number" value={p.sort_order} onChange={(e) => onChange({ ...p, sort_order: Number(e.target.value) })} />
                            </div>
                        </>
                    )}
                    {modal.type === 'route' && (
                        <>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Next Question</label>
                                <select disabled={p.is_terminal} value={p.next_question_id} onChange={(e) => onChange({ ...p, next_question_id: e.target.value })}>
                                    <option value="">Next question</option>
                                    {questions.map((q) => <option key={q.id} value={q.id}>{q.question_text}</option>)}
                                </select>
                            </div>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Terminal</label>
                                <label className="radioRow"><input type="checkbox" checked={!!p.is_terminal} onChange={(e) => onChange({ ...p, is_terminal: e.target.checked })} /><span>Yes</span></label>
                            </div>
                            <div className="fieldGroup">
                                <label className="fieldLabel">Terminal Message</label>
                                <input disabled={!p.is_terminal} value={p.terminal_message} onChange={(e) => onChange({ ...p, terminal_message: e.target.value })} />
                            </div>
                        </>
                    )}
                </div>
                <div className="modalActions">
                    <button type="button" className="btnGhost" onClick={onCancel}>Cancel</button>
                    <button type="button" onClick={onSave}>Save</button>
                </div>
            </div>
        </div>
    );
}

function ActionTable({ headers, rows }) {
    return (
        <table>
            <thead><tr>{headers.map((h) => <th key={h}>{h}</th>)}</tr></thead>
            <tbody>
                {rows.length === 0 ? (
                    <tr><td colSpan={headers.length}>No data</td></tr>
                ) : rows.map((row, i) => (
                    <tr key={i}>{row.map((cell, j) => <td key={j}>{cell}</td>)}</tr>
                ))}
            </tbody>
        </table>
    );
}

function ReportPage() {
    const [sessions, setSessions] = useState([]);
    const [detailModal, setDetailModal] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailError, setDetailError] = useState('');
    const [flows, setFlows] = useState([]);
    const [activeFlowId, setActiveFlowId] = useState(null);
    const [selectedFlowId, setSelectedFlowId] = useState(null);
    useEffect(() => {
        api('/admin-api/flow').then((d) => {
            setSessions(d.sessions || []);
            setFlows(d.questionnaires || []);
            setActiveFlowId(d.active_questionnaire_id || null);
            setSelectedFlowId(d.questionnaire?.id || null);
        });
    }, []);

    const loadFlow = async (qid) => {
        const d = await api(`/admin-api/flow?questionnaire_id=${encodeURIComponent(String(qid))}`);
        setSessions(d.sessions || []);
        setFlows(d.questionnaires || []);
        setActiveFlowId(d.active_questionnaire_id || null);
        setSelectedFlowId(d.questionnaire?.id || qid || null);
    };

    const started = sessions.length;
    const completed = sessions.filter((s) => !!s.completed_at).length;
    const inProgress = started - completed;
    const submitted = sessions.filter((s) => (
        !!s.completed_at
        && !!s.email
        && !!s.phone
        && String(s.name || '').trim() !== ''
    ));
    const completedSessions = sessions.filter((s) => !!s.completed_at);

    const openDetails = async (sessionId) => {
        setDetailLoading(true);
        setDetailError('');
        try {
            const data = await api(`/admin-api/sessions/${sessionId}/answers`);
            setDetailModal(data);
        } catch (err) {
            setDetailError(err.message || 'Could not load details.');
        } finally {
            setDetailLoading(false);
        }
    };

    return (
        <section className="panel">
            <header className="panelHead"><h1>Live Reports</h1><p>Conversion and flow completion metrics.</p></header>
            <div className="cardBlock">
                <div className="form">
                    <div className="fieldGroup">
                        <label className="fieldLabel">Flow</label>
                        <select value={selectedFlowId || ''} onChange={(e) => loadFlow(Number(e.target.value))}>
                            {flows.map((f) => (
                                <option key={f.id} value={f.id}>
                                    {f.title}{Number(f.id) === Number(activeFlowId) ? ' (Active)' : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>
            </div>
            <div className="kpis">
                <div><span>Started</span><strong>{started}</strong></div>
                <div><span>Completed</span><strong>{completed}</strong></div>
                <div><span>Submitted</span><strong>{submitted.length}</strong></div>
            </div>
            <p className="muted">In progress: {inProgress}</p>
            <div className="cardBlock">
                <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Submitted</th><th>Details</th></tr></thead>
                    <tbody>
                        {completedSessions.map((s) => (
                            <tr key={s.id}>
                                <td>{s.id}</td>
                                <td>{s.name || '-'}</td>
                                <td>{s.email || '-'}</td>
                                <td>{s.phone || '-'}</td>
                                <td>{(s.email && s.phone && String(s.name || '').trim() !== '') ? 'Yes' : 'No'}</td>
                                <td>
                                    <button type="button" className="btnGhost iconBtn" onClick={() => openDetails(s.id)} disabled={detailLoading}>
                                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" stroke="currentColor" strokeWidth="2" />
                                            <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" stroke="currentColor" strokeWidth="2" />
                                        </svg>
                                        View
                                    </button>
                                </td>
                            </tr>
                        ))}
                        {completedSessions.length === 0 && (
                            <tr><td colSpan={6}>No completed entries yet.</td></tr>
                        )}
                    </tbody>
                </table>
            </div>
            <p className="muted"><Link to="/admin">Back to Builder</Link></p>

            {detailError && <p className="error">{detailError}</p>}
            {detailModal && (
                <AnswersModal
                    data={detailModal}
                    onClose={() => setDetailModal(null)}
                />
            )}
        </section>
    );
}

function AnswersModal({ data, onClose }) {
    const session = data?.session;
    const qa = data?.qa || [];
    return (
        <div className="modalOverlay">
            <div className="modalBox">
                <h3>Submission Details</h3>
                <p className="muted">#{session?.id} • {session?.name || '-'} • {session?.email || '-'} • {session?.phone || '-'}</p>
                <div className="cardBlock" style={{ padding: 0, border: 'none', boxShadow: 'none' }}>
                    <table>
                        <thead><tr><th>Question</th><th>Answer(s)</th></tr></thead>
                        <tbody>
                            {qa.length === 0 ? (
                                <tr><td colSpan={2}>No answers captured.</td></tr>
                            ) : qa.map((row) => (
                                <tr key={row.question_id}>
                                    <td>{row.question_text}</td>
                                    <td>{(row.answers || []).map((a) => a.option_text).join(', ')}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="modalActions">
                    <button type="button" className="btnGhost" onClick={onClose}>Close</button>
                </div>
            </div>
        </div>
    );
}

const rootNode = document.getElementById('app');
if (rootNode) createRoot(rootNode).render(<AppShell />);
