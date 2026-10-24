import {useState} from 'react';
import {Eye,EyeOff,LockKeyhole,LoaderCircle} from 'lucide-react';
export default function Login({checking,busy,error,onSubmit}:{checking:boolean;busy:boolean;error:string;onSubmit:(email:string,password:string)=>Promise<void>}){
 const [visible,setVisible]=useState(false);
 return <main className="auth-page"><section className="auth-card" aria-label="Administrator sign in"><div className="auth-brand"><img className="auth-brand-image" src="./favicon.png" alt=""/><div>Khushrang Events<small>ADMINISTRATION</small></div></div><div className="auth-lock"><LockKeyhole size={22}/></div><h1>Sign in to your workspace</h1><p>Welcome back. Enter your account details to continue.</p>
 {checking?<div className="auth-loading" role="status"><LoaderCircle className="spin" size={18}/>Checking your session…</div>:<form onSubmit={async e=>{e.preventDefault();const f=new FormData(e.currentTarget);await onSubmit(String(f.get('email')),String(f.get('password')))}}>
 <label htmlFor="login-email">Email address</label><input id="login-email" name="email" type="email" autoComplete="username" placeholder="you@khushrangevents.com" required disabled={busy}/>
 <label htmlFor="login-password">Password</label><div className="auth-password"><input id="login-password" name="password" type={visible?'text':'password'} autoComplete="current-password" required disabled={busy}/><button type="button" aria-label={visible?'Hide password':'Show password'} onClick={()=>setVisible(!visible)}>{visible?<EyeOff size={18}/>:<Eye size={18}/>}</button></div>
 {error&&<div className="auth-error" role="alert">{error}</div>}
 <button className="primary auth-submit" disabled={busy}>{busy?<><LoaderCircle className="spin" size={17}/>Signing in…</>:'Sign in'}</button></form>}
 <div className="auth-footer"><LockKeyhole size={13}/>Authorized team members only</div></section><span className="auth-copyright">Khushrang Events · Private administration</span></main>
}
