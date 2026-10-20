"""Strict, offline legacy-v1 backup inventory and normalized partial import plan.

No browser access, SQL, source rewriting, defaults from normalizeState or secrets.
The mapping contains paths/types, never a second copy of source business values.
"""
from collections import Counter
from datetime import date, datetime, timezone
from decimal import Decimal, InvalidOperation
import hashlib
import json
import re
import unicodedata

ROOT_ARRAYS = 'rates projects plans products customers sales subs expenses resources suppliers events treasury integrations review timeLogs log'.split()
SET_ARRAYS = 'currencies brands channels categories formats languages expenseGroups payMethods accounts'.split()
PENDING = set(ROOT_ARRAYS) - {'projects', 'plans', 'products', 'customers'}
TABLES = ['currencies','brands','channels','projects','customers','accounts','business_settings',
          'product_categories','formats','languages','payment_methods','expense_groups','expense_categories',
          'plans','plan_prices','products','product_versions','product_links','bundle_items',
          'product_channels','product_version_channels','project_user_stats','product_version_history']
ACTIVITY_TABLES = ['subscriptions','subscription_events','time_logs','integrations','suppliers']
SNAPSHOT_TABLES = ['original_financial_snapshots']
REFS = {
 'projects': {'brand_id':'brands','investment_currency':'currencies'},
 'accounts': {'currency_id':'currencies'}, 'business_settings':{'base_currency':'currencies'},
 'product_categories':{'brand_id':'brands'},'expense_categories':{'group_id':'expense_groups'},
 'plans':{'project_id':'projects'},'plan_prices':{'plan_id':'plans','currency_id':'currencies'},
 'products':{'brand_id':'brands','project_id':'projects','category_id':'product_categories'},
 'product_versions':{'product_id':'products','format_id':'formats','language_id':'languages','currency_id':'currencies'},
 'product_links':{'product_id':'products'},'bundle_items':{'bundle_product_id':'products','component_product_id':'products'},
 'product_version_channels':{'version_id':'product_versions','channel_id':'channels'},
 'project_user_stats':{'project_id':'projects'},
 'subscriptions':{'project_id':'projects','plan_id':'plans','customer_id':'customers'},
 'subscription_events':{'subscription_id':'subscriptions','plan_id':'plans','currency_id':'currencies'},
 'time_logs':{'project_id':'projects'}, 'integrations':{'project_id':'projects'},
}
SECRET = re.compile(r'password|passwd|secret|token|api[_-]?key|credential|authorization|^pin$', re.I)


def digest(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',',':')).encode()).hexdigest()


def child_id(*parts):
    return 'imp_' + digest(parts)[:60]


def decode(raw):
    def pairs(items):
        out = {}
        for k,v in items:
            if k in out:
                raise ValueError('duplicate_json_key')
            out[k] = v
        return out
    if len(raw) > 32*1024*1024:
        raise ValueError('source_over_32MiB')
    return json.loads(raw.decode('utf-8-sig'), parse_float=Decimal,
                      parse_constant=lambda _: (_ for _ in ()).throw(ValueError('nonfinite_number')),
                      object_pairs_hook=pairs)


class Model:
    def __init__(self, raw, environment, activity=False):
        self.raw_hash = hashlib.sha256(raw).hexdigest()
        self.environment = environment
        self.activity = activity
        self.tables = TABLES + (ACTIVITY_TABLES if activity else []) + (SNAPSHOT_TABLES if activity else [])
        self.rows = {t:[] for t in self.tables}
        self.mapping = []
        self.issues = []
        self.counts = {}
        self.normalizations = []
        self.source = None
        try:
            self.source = decode(raw)
            self.build()
        except (ValueError, TypeError, KeyError, InvalidOperation, OverflowError, RecursionError) as e:
            self.issue('error', [], 'invalid_backup_structure', detail=type(e).__name__)

    def issue(self, level, path, code, **extra):
        # Paths/codes only: never include input values or unknown secret names.
        path = ['[private-field]' if isinstance(p,str) and SECRET.search(p) else p for p in path]
        self.issues.append(dict(level=level,path=path,code=code,**extra))

    def obj(self, value, path, allowed):
        if not isinstance(value,dict):
            self.issue('error',path,'object_required'); return {}
        for key in value:
            if key not in allowed:
                self.issue('review',path+[key],'secret_excluded' if SECRET.search(key) else 'unknown_field')
        return value

    def arr(self, value, path):
        if not isinstance(value,list):
            self.issue('error',path,'array_required'); return []
        self.counts['/'.join(map(str,path))] = len(value)
        return value

    def container(self, path, kind):
        self.mapping.append({'path':path,'container':kind})

    def add(self, table, ident, data, path):
        if not isinstance(ident,str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}',ident):
            self.issue('error',path+['id'],'invalid_or_missing_id'); return None
        if any(r['id']==ident for r in self.rows[table]):
            self.issue('error',path+['id'],'duplicate_id'); return None
        row = dict(id=ident, **data)
        self.rows[table].append(row)
        return row

    def scalar(self, table, row, obj, key, path, column=None, kind='text', required=False, default=None):
        column = column or key
        if key not in obj:
            if required:
                self.issue('error',path+[key],'required_field_missing')
            else:
                self.issue('optional',path+[key],'absent_source_field',sql_value='NULL' if default is None else 'documented_internal_default')
            row[column] = default
            return
        value = obj[key]
        try:
            normalized = self.convert(value,kind)
        except (ValueError,TypeError,InvalidOperation,OverflowError):
            self.issue('error',path+[key],'invalid_'+kind); normalized = default
        row[column] = normalized
        if value != normalized:
            self.normalizations.append({'path':path+[key],'rule':kind})
        self.mapping.append({'path':path+[key],'table':table,'id':row['id'],'column':column,'kind':kind})

    @staticmethod
    def convert(v, kind):
        if kind.startswith('nullable_'):
            if v is None or v=='': return None
            kind = kind[9:]
        if kind in ('money','percent'):
            if isinstance(v,bool) or not isinstance(v,(str,int,Decimal)): raise ValueError()
            text = str(v)
            scale = 6 if kind=='percent' else 2
            if not re.fullmatch(r'(0|[1-9][0-9]{0,17})(\.[0-9]{1,'+str(scale)+'})?',text): raise ValueError()
            number = Decimal(text)
            if kind=='percent' and number>=100: raise ValueError()
            return format(number, '.6f' if kind=='percent' else '.2f')
        if kind=='integer':
            if type(v) is not int or not 0<=v<=2147483647: raise ValueError()
            return v
        if kind=='boolean':
            if type(v) is not bool: raise ValueError()
            return int(v)
        if kind=='date':
            if not isinstance(v,str) or not re.fullmatch(r'\d{4}-\d\d-\d\d',v) or date.fromisoformat(v).year<1000: raise ValueError()
            return v
        if kind=='timestamp':
            if not isinstance(v,str) or not re.fullmatch(r'\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)',v): raise ValueError()
            dt = datetime.fromisoformat(v.replace('Z','+00:00')).astimezone(timezone.utc)
            if dt.year<1000: raise ValueError()
            return dt.strftime('%Y-%m-%d %H:%M:%S.%f')
        if not isinstance(v,str): raise ValueError()
        if v!=v.strip(): raise ValueError('ambiguous_whitespace')
        if re.search(r'(?:password|passwd|secret|api[_-]?key|token)\s*[=:]\s*[^\s&]+|https?://[^/\s:@]+:[^/\s@]+@',v,re.I):
            raise ValueError('possible_embedded_secret')
        if kind=='id' and not re.fullmatch(r'[A-Za-z0-9_-]{1,64}',v): raise ValueError()
        if kind=='currency' and not re.fullmatch(r'[A-Z]{3,5}',v): raise ValueError()
        if kind=='color' and not re.fullmatch(r'#[0-9a-fA-F]{6}',v): raise ValueError()
        if kind=='email':
            if not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+',v): raise ValueError()
            return v.lower()
        limits={'text':160,'long':16000,'url':2048,'link':4096,'symbol':16,'version':64,'short':255,'country':120}
        if len(v)>limits.get(kind,160): raise ValueError()
        if kind=='symbol' and not v:raise ValueError()
        if kind=='url' and v and not re.fullmatch(r'https?://[^\s/]+(?:[^\s]*)?',v): raise ValueError()
        return v

    def record(self, table, obj, path, specs, defaults=None, identity='id'):
        obj = self.obj(obj,path,set(specs)|{identity,'archivedAt','events','origin','createdAt','errors','stats'})
        row = self.add(table,obj.get(identity),defaults or {},path)
        if row is None: return None
        self.container(path,'object')
        row['archived_at'] = None
        if 'archivedAt' in obj:
            self.scalar(table,row,obj,'archivedAt',path,'archived_at','nullable_timestamp')
        self.mapping.append({'path':path+[identity],'table':table,'id':row['id'],'column':'id','kind':'text'})
        for key, spec in specs.items():
            if spec is None: continue
            col,kind,required,default = spec
            self.scalar(table,row,obj,key,path,col,kind,required,default)
        return row

    def build(self):
        s = self.obj(self.source,[],set(ROOT_ARRAYS)|{'version','setupDone','createdAt','settings'})
        if s.get('version')!=1 or type(s.get('version')) is not int:
            self.issue('error',['version'],'unsupported_version')
        if self.environment not in ('produccion','pruebas'):
            self.issue('error',[],'explicit_environment_required')
        for key in ('version','setupDone','createdAt'):
            if key in s: self.issue('pending',[key],'metadata_not_in_schema')
        for key,kind in [('setupDone','boolean'),('createdAt','timestamp')]:
            if key in s:
                try:self.convert(s[key],kind)
                except (TypeError,ValueError):self.issue('error',[key],'invalid_'+kind)
        settings = self.obj(s.get('settings'),['settings'],set(SET_ARRAYS)|{'business','baseCurrency','numFormat','alerts','hourly','pin'})
        self.container([],'object'); self.container(['settings'],'object')
        for key in ('alerts','hourly','pin'):
            if key in settings: self.issue('pending',['settings',key],'secret_excluded' if key=='pin' else 'not_in_schema')
        for key,allowed in [('alerts',{'upcomingDays','noSalesDays','dropPct'}),('hourly',{'rate','currency'})]:
            if key in settings:
                obj=self.obj(settings[key],['settings',key],allowed)
                for field in obj:self.issue('pending',['settings',key,field],'not_in_schema')
        for key in SET_ARRAYS:
            if key not in settings:
                self.issue('optional',['settings',key],'collection_absent'); continue
            self.arr(settings[key],['settings',key]); self.container(['settings',key],'array')
        for key in ROOT_ARRAYS:
            if key not in s:
                self.issue('optional',[key],'collection_absent'); continue
            self.arr(s[key],[key])
            if key in PENDING:
                self.issue('pending',[key],'collection_not_imported',count=len(s[key]))
            else: self.container([key],'array')
        def spec(col,kind='text',required=False,default=''): return (col,kind,required,default)
        for i,o in enumerate(settings.get('currencies',[])):
            self.record('currencies',o,['settings','currencies',i],{'symbol':spec('symbol','symbol',True)},identity='code')
        for i,o in enumerate(settings.get('brands',[])):
            self.record('brands',o,['settings','brands',i],{'name':spec('name',required=True),
                **{k:spec(k,'color',True) for k in ['color','dark','alt']},'desc':spec('description','long')})
        for i,o in enumerate(settings.get('channels',[])):
            self.record('channels',o,['settings','channels',i],{'name':spec('name',required=True),'commission':spec('commission_pct','nullable_percent',False,None)})
        for i,o in enumerate(settings.get('accounts',[])):
            self.record('accounts',o,['settings','accounts',i],{'name':spec('name',required=True),'currency':spec('currency_id','currency',True)})
        if 'business' in settings or settings.get('baseCurrency'):
            b = self.obj(settings.get('business',{}),['settings','business'],{'name','owner','site'})
            # Empty onboarding has no business settings row; never manufacture a currency/name.
            if settings.get('baseCurrency') and b.get('name'):
                r = self.add('business_settings','main',{'archived_at':None},['settings','business'])
                self.container(['settings','business'],'object')
                for k in ('name','owner','site'):
                    self.scalar('business_settings',r,b,k,['settings','business'],kind='url' if k=='site' else 'text',required=k=='name',default='')
                self.scalar('business_settings',r,settings,'baseCurrency',['settings'],'base_currency','currency',True)
                self.scalar('business_settings',r,settings,'numFormat',['settings'],'num_format','text',True)
            else:
                self.issue('pending',['settings','business'],'unconfigured_business_not_representable')
                for k in ('baseCurrency','numFormat'):
                    if k in settings: self.issue('pending',['settings',k],'unconfigured_business_not_representable')
        for key,table in [('formats','formats'),('languages','languages'),('payMethods','payment_methods')]:
            for i,v in enumerate(settings.get(key,[])):
                p=['settings',key,i]; r=self.add(table,child_id(key,i),{'sort_order':i,'archived_at':None},p)
                self.scalar(table,r,{'value':v},'value',p[:-1], 'name','text',True)
                self.mapping[-1]['path']=p
        for i,o in enumerate(settings.get('categories',[])):
            p=['settings','categories',i];o=self.obj(o,p,{'brandId','name'})
            r=self.add('product_categories',child_id('categories',i),{'sort_order':i,'archived_at':None},p); self.container(p,'object')
            self.scalar('product_categories',r,o,'brandId',p,'brand_id','id',True)
            self.scalar('product_categories',r,o,'name',p,required=True)
        for i,o in enumerate(settings.get('expenseGroups',[])):
            p=['settings','expenseGroups',i];o=self.obj(o,p,{'name','items'});self.container(p,'object')
            r=self.add('expense_groups',child_id('groups',i),{'sort_order':i,'archived_at':None},p)
            self.scalar('expense_groups',r,o,'name',p,required=True)
            if 'items' in o:
                self.container(p+['items'],'array')
                for j,v in enumerate(self.arr(o['items'],p+['items'])):
                    c=self.add('expense_categories',child_id('groups',i,j),{'sort_order':j,'group_id':r['id'],'archived_at':None},p+['items',j])
                    self.scalar('expense_categories',c,{'v':v},'v',p,'name','text',True);self.mapping[-1]['path']=p+['items',j]
        for i,o in enumerate(s.get('projects',[])):
            p=['projects',i]
            specs={k:spec(col,kind,req,default) for k,col,kind,req,default in [
                ('name','name','text',True,''),('brandId','brand_id','id',True,''),('kind','kind','text',True,''),('status','status','text',True,''),
                ('launchDate','launch_date','nullable_date',False,None),('url','url','url',False,''),('desc','description','long',False,''),
                ('domain','domain','short',False,''),('repo','repo','url',False,''),('hosting','hosting','short',False,''),('database','database_description','short',False,''),
                ('tech','tech','short',False,''),('milestone','milestone','short',False,''),('initialInvestment','initial_investment','nullable_money',False,None),
                ('investCurrency','investment_currency','nullable_currency',False,None),('notes','notes','long',False,''),
                ('createdAt','source_created_at','nullable_timestamp',False,None),('updatedAt','source_updated_at','nullable_timestamp',False,None)]}
            r=self.record('projects',o,p,{**specs,'userStats':None})
            if r and 'userStats' in o:
                self.container(p+['userStats'],'array')
                for j,v in enumerate(self.arr(o['userStats'],p+['userStats'])):
                    q=p+['userStats',j];v=self.obj(v,q,{'date','registered','active','origin'});self.container(q,'object')
                    t=self.add('project_user_stats',child_id('stats',r['id'],j),{'project_id':r['id'],'sequence_no':j,'archived_at':None},q)
                    for key,col,kind,req,default in [('date','business_date','date',True,None),('registered','registered','integer',True,None),('active','active','integer',True,None),('origin','origin','nullable_text',False,None)]:
                        self.scalar('project_user_stats',t,v,key,q,col,kind,req,default)
        for i,o in enumerate(s.get('customers',[])):
            p=['customers',i]
            self.record('customers',o,p,{'name':spec('name',required=True),'email':spec('email','nullable_email',False,None),'country':spec('country','country'),
                'createdAt':spec('business_date','nullable_date',False,None),'consent':spec('consent','boolean',False,0),
                'notes':spec('notes','long'),'origin':spec('origin','text',False,'importado')})
            for key in ('consent','origin'):
                if isinstance(o,dict) and key not in o:
                    # Existing NOT NULL columns cannot represent this historical unknown.
                    # Do not turn absence into false consent or an invented business origin.
                    self.issue('review',p+[key],'absence_not_representable_in_current_schema')
        for i,o in enumerate(s.get('plans',[])):
            p=['plans',i]
            r=self.record('plans',o,p,{'name':spec('name',required=True),'projectId':spec('project_id','id',True),
                'trialDays':spec('trial_days','integer',False,0),'price':None,'currency':None,'period':None},defaults={'status':'activa','sort_order':i})
            if not r: continue
            if 'trialDays' not in o:self.issue('review',p+['trialDays'],'absence_not_representable_in_current_schema')
            t=self.add('plan_prices',child_id('price',r['id']),{'plan_id':r['id'],'valid_from':None,'valid_to':None,'provenance':'unknown','archived_at':None},p)
            for k,c,kind in [('price','price','money'),('currency','currency_id','currency'),('period','period','text')]:
                self.scalar('plan_prices',t,o,k,p,c,kind,True)
            self.issue('normalization',p,'price_history_unknown_dates')
        for i,o in enumerate(s.get('products',[])):
            p=['products',i]
            r=self.record('products',o,p,{'name':spec('name',required=True),'brandId':spec('brand_id','id',True),
                'projectId':spec('project_id','nullable_id',False,None),'category':spec('category_label_snapshot'),
                'status':spec('status',required=True),'launchDate':spec('launch_date','nullable_date',False,None),
                'pendingUpdates':spec('pending_updates','long'),'createdAt':spec('source_created_at','nullable_timestamp',False,None),
                'updatedAt':spec('source_updated_at','nullable_timestamp',False,None),'versions':None,'bundle':None,'resources':None},defaults={'category_id':None})
            if not r: continue
            cats=[c for c in self.rows['product_categories'] if c['brand_id']==r['brand_id'] and c['name']==r['category_label_snapshot']]
            if len(cats)==1:r['category_id']=cats[0]['id']
            elif r['category_label_snapshot']:self.issue('normalization',p+['category'],'historical_category_label_only')
            for key,table,owner,valuecol in [('resources','product_links','product_id','link_value'),('bundle','bundle_items','bundle_product_id','component_product_id')]:
                if key not in o: continue
                self.container(p+[key],'array')
                for j,v in enumerate(self.arr(o[key],p+[key])):
                    t=self.add(table,child_id(key,r['id'],j),{owner:r['id'],'sort_order':j,'archived_at':r['archived_at']},p+[key,j])
                    self.scalar(table,t,{'v':v},'v',p,valuecol,'id' if key=='bundle' else 'link',True);self.mapping[-1]['path']=p+[key,j]
            if 'versions' not in o: continue
            self.container(p+['versions'],'array')
            for j,v in enumerate(self.arr(o['versions'],p+['versions'])):
                q=p+['versions',j]
                t=self.record('product_versions',v,q,{'format':spec('format_label',required=True),'language':spec('language_label'),
                    'version':spec('version_label','version'),'price':spec('price','money',True),'currency':spec('currency_id','currency',True),'channels':None},
                    defaults={'product_id':r['id'],'sort_order':j,'format_id':None,'language_id':None})
                if not t:continue
                if r['archived_at'] and not t['archived_at']:t['archived_at']=r['archived_at']
                for cat,col,label in [('formats','format_id','format_label'),('languages','language_id','language_label')]:
                    matches=[c for c in self.rows[cat] if c['name']==t[label]]
                    if len(matches)==1:t[col]=matches[0]['id']
                if 'channels' in v:
                    self.container(q+['channels'],'array')
                    for n,ch in enumerate(self.arr(v['channels'],q+['channels'])):
                        z=self.add('product_version_channels',child_id('channels',t['id'],n),{'version_id':t['id'],'sort_order':n,'archived_at':t['archived_at']},q+['channels',n])
                        self.scalar('product_version_channels',z,{'v':ch},'v',q,'channel_id','id',True);self.mapping[-1]['path']=q+['channels',n]
                history=dict(t);history.pop('id');history['version_id']=t['id'];history['revision']=1
                history['version_archived_at']=history.pop('archived_at');history['archived_at']=None
                self.add('product_version_history',hashlib.sha256((t['id']+':1').encode()).hexdigest(),history,q)
        if self.activity: self.build_activity(s)
        self.validate_rows()
        self.inspect_pending(s)

    def build_activity(self, source):
        """Map only the five entities represented by migration 012."""
        def add_activity(table, obj, path, specs, identity='id'):
            return self.record(table, obj, path, specs, identity=identity)
        for i,o in enumerate(source.get('subs',[])):
            p=['subs',i]
            r=add_activity('subscriptions',o,p,{
                'projectId':('project_id','id',True,None),'planId':('plan_id','id',False,None),
                'customerId':('customer_id','id',False,None),'status':('status','short',True,None),
                'startDate':('start_date','nullable_date',False,None),'nextRenewal':('next_renewal','nullable_date',False,None),
                'cancelDate':('cancel_date','nullable_date',False,None),'externalId':('external_id','nullable_short',False,None)})
            if r:
                r['source_origin']=o.get('origin'); r['source_origin_present']=str(int('origin' in o))
                r['source_created_at']=self.convert(o.get('createdAt'),'timestamp') if o.get('createdAt') else None; r['source_created_at_present']=str(int('createdAt' in o))
                for k in ('origin','createdAt'):
                    if k in o:self.mapping.append({'path':p+[k],'table':'subscriptions','id':r['id'],'column':'source_'+('origin' if k=='origin' else 'created_at'),'kind':'text'})
                for j,e in enumerate(o.get('events',[]) if isinstance(o.get('events',[]),list) else []):
                    ep=p+['events',j]
                    er=self.record('subscription_events',e,ep,{
                        'date':('event_date','date',True,None),'type':('event_type','short',True,None),
                        'planId':('plan_id','nullable_id',False,None),'amount':('amount','nullable_money',False,None),
                        'currency':('currency_id','nullable_id',False,None),'saleId':('sale_id','nullable_id',False,None),
                        'note':('note','nullable_long',False,None)})
                    if er:
                        er['subscription_id']=r['id']
                        if er.get('note') is None:
                            er['note']=''; self.normalizations.append({'path':ep+['note'],'rule':'sql_not_null_empty_string'})
                        self.mapping.append({'path':ep,'container':'object'})
        for i,o in enumerate(source.get('timeLogs',[])):
            rlog=self.record('time_logs',o,['timeLogs',i],{
                'projectId':('project_id','id',True,None),'date':('logged_on','date',True,None),
                'hours':('hours','text',True,None),'kind':('kind','short',True,None),'note':('note','nullable_long',False,None)})
            if rlog and rlog.get('note') is None:
                rlog['note']=''; self.normalizations.append({'path':['timeLogs',i,'note'],'rule':'sql_not_null_empty_string'})
        for i,o in enumerate(source.get('integrations',[])):
            self.record('integrations',o,['integrations',i],{
                'name':('name','short',True,None),'kind':('kind','short',True,None),'method':('method','short',True,None),
                'status':('status','short',True,None),'projectId':('project_id','nullable_id',False,None),
                'lastSync':('last_sync','nullable_timestamp',False,None),'notes':('notes','nullable_long',False,None)},defaults={'errors_json':'[]'})
            if i < len(self.rows['integrations']):
                r=self.rows['integrations'][-1]; errors=o.get('errors',[])
                if not isinstance(errors,list): self.issue('error',['integrations',i,'errors'],'array_required')
                r['errors_json']=json.dumps(errors if isinstance(errors,list) else [],ensure_ascii=False,separators=(',',':'))
                r['source_stats']=json.dumps(o.get('stats'),ensure_ascii=False,separators=(',',':')) if 'stats' in o else None
                r['source_stats_present']=str(int('stats' in o))
                if r.get('notes') is None:r['notes']=''
                self.mapping.append({'path':['integrations',i,'errors'],'table':'integrations','id':r['id'],'column':'errors_json','kind':'json'})
                self.mapping.append({'path':['integrations',i,'stats'],'table':'integrations','id':r['id'],'column':'source_stats','kind':'json'}) if 'stats' in o else None
        for i,o in enumerate(source.get('suppliers',[])):
            rs=self.record('suppliers',o,['suppliers',i],{
                'name':('name','short',True,None),'kind':('kind','short',False,None),'url':('url','nullable_url',False,None),
                'contact':('contact','nullable_short',False,None),'taxId':('tax_id','nullable_short',False,None),
                'notes':('notes','nullable_long',False,None)})
            if rs:
                for k in ('kind','url','contact','tax_id','notes'):
                    if rs.get(k) is None:
                        rs[k]=''; self.normalizations.append({'path':['suppliers',i,k],'rule':'sql_not_null_empty_string'})
        for collection in ('sales','expenses','treasury'):
            for i,o in enumerate(source.get(collection,[])):
                if not isinstance(o,dict) or not isinstance(o.get('id'),str):
                    self.issue('error',[collection,i],'invalid_or_missing_id'); continue
                ident=collection+'_'+o['id']
                payload=json.dumps(o,ensure_ascii=False,separators=(',',':'))
                self.rows['original_financial_snapshots'].append({'id':ident,'collection':collection,'ordinal':str(i),
                    'source_path':collection+'/'+str(i),'payload':payload,'content_hash':hashlib.sha256(payload.encode()).hexdigest(),
                    'import_status':'historical','normalized_json':None})

    def validate_rows(self):
        by={t:{r['id']:r for r in rs} for t,rs in self.rows.items()}
        unique={'brands':('name',),'channels':('name',),'projects':('name',),'customers':('email',),
                'formats':('name',),'languages':('name',),'payment_methods':('name',),'expense_groups':('name',),
                'product_categories':('brand_id','name'),'expense_categories':('group_id','name'),
                'plans':('project_id','name'),'products':('brand_id','name'),
                'product_versions':('product_id','format_label','language_label','version_label'),
                'bundle_items':('bundle_product_id','component_product_id'),
                'product_version_channels':('version_id','channel_id')}
        for table,columns in unique.items():
            seen=set()
            for row in self.rows[table]:
                values=tuple(row.get(c) for c in columns)
                if None in values:continue
                # Conservative offline warning; PDO preflight additionally uses real SQL weights.
                key=tuple(''.join(ch for ch in unicodedata.normalize('NFKD',str(v)).casefold() if not unicodedata.combining(ch))
                          if c in ('name','email','format_label','language_label','version_label') else v for c,v in zip(columns,values))
                if key in seen:self.issue('review',[table,row['id']],'duplicate_or_colliding_key')
                seen.add(key)
        for table,rs in self.rows.items():
            for r in rs:
                p=[table,r['id']]
                if 'name' in r and not r['name']:self.issue('error',p+['name'],'name_required')
                if table=='currencies' and not re.fullmatch(r'[A-Z]{3,5}',r['id']):self.issue('error',p,'invalid_currency')
                for col,target in REFS.get(table,{}).items():
                    ref=r.get(col)
                    if ref is None:continue
                    parent=by[target].get(ref)
                    if parent is None:self.issue('error',p+[col],'missing_reference',target=target)
                    elif parent.get('archived_at') and not r.get('archived_at'):
                        self.issue('review',p+[col],'reference_to_archived_entity',target=target)
        enums={'projects':{'kind':['descargable','pago_unico','suscripcion','desarrollo','interna'],'status':['idea','desarrollo','pruebas','publicada','pausada','retirada']},
               'products':{'status':['borrador','publicado','actualizar','pausado','retirado']},
               'plan_prices':{'period':['mensual','anual']},'customers':{'origin':['manual','importado','integracion','estimado']},
               'business_settings':{'num_format':['es','en']},'project_user_stats':{'origin':[None,'manual','importado','integracion','estimado']}}
        for t,cols in enums.items():
            for r in self.rows[t]:
                for c,allowed in cols.items():
                    if r.get(c) not in allowed:self.issue('error',[t,r['id'],c],'invalid_enum')
        graph={}
        for e in self.rows['bundle_items']:graph.setdefault(e['bundle_product_id'],[]).append(e['component_product_id'])
        visited=set();active=set()
        def visit(k):
            if k in active:raise ValueError('cycle')
            if k in visited:return
            active.add(k)
            for child in graph.get(k,[]):visit(child)
            active.remove(k);visited.add(k)
        try:
            for k in graph:visit(k)
        except (ValueError,RecursionError):self.issue('error',['products'],'bundle_cycle')

    def inspect_pending(self,s):
        """Inventory and conservative structural checks on unimplemented collections.

        Their domain-specific accounting semantics remain explicitly unsupported.
        Unknown nested fields are reported as unassessed, never discarded.
        """
        ids={}
        for key in sorted(PENDING):
            for i,r in enumerate(s.get(key,[])):
                if not isinstance(r,dict):self.issue('error',[key,i],'object_required');continue
                ident=r.get('id')
                if not isinstance(ident,str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}',ident):
                    self.issue('error',[key,i,'id'],'invalid_or_missing_id')
                elif (key,ident) in ids:self.issue('error',[key,i,'id'],'duplicate_id')
                else:ids[key,ident]=True
        targets={'projectId':self.rows['projects'],'brandId':self.rows['brands'],'customerId':self.rows['customers'],
                 'productId':self.rows['products'],'versionId':self.rows['product_versions'],'planId':self.rows['plans'],
                 'channelId':self.rows['channels'],'accountId':self.rows['accounts']}
        for field,key in [('subId','subs'),('supplierId','suppliers'),('resourceId','resources'),('saleId','sales'),('expenseId','expenses')]:
            targets[field]=[{'id':ident} for collection,ident in ids if collection==key]
        targets['accountFrom']=targets['accountTo']=self.rows['accounts']
        currencies={r['id'] for r in self.rows['currencies']}
        def walk(v,p):
            if isinstance(v,list):
                self.counts['/'.join(map(str,p))]=len(v)
                for i,x in enumerate(v):walk(x,p+[i])
            elif isinstance(v,dict):
                if 'alloc' in p and 'targets' in p and v.get('type') in ('brand','project'):
                    table='brands' if v['type']=='brand' else 'projects'
                    if v.get('id') not in {r['id'] for r in self.rows[table]}:self.issue('error',p+['id'],'missing_allocation_reference')
                if len(p)==2 and p[0]=='sales' and v.get('versionId'):
                    version=next((r for r in self.rows['product_versions'] if r['id']==v['versionId']),None)
                    if version and v.get('productId')!=version['product_id']:self.issue('error',p+['versionId'],'version_product_mismatch')
                if len(p)==2 and p[0]=='subs' and v.get('planId'):
                    plan=next((r for r in self.rows['plans'] if r['id']==v['planId']),None)
                    if plan and v.get('projectId')!=plan['project_id']:self.issue('error',p+['planId'],'plan_project_mismatch')
                for k,x in v.items():
                    q=p+[k]
                    if SECRET.search(k):self.issue('pending',q,'secret_excluded');continue
                    self.issue('pending',q,'unsupported_field_unassessed')
                    if k in targets and x:
                        matches=[r for r in targets[k] if r['id']==x]
                        if not matches:self.issue('error',q,'missing_reference')
                        elif matches[0].get('archived_at'):self.issue('review',q,'reference_to_archived_entity')
                    if k=='projectIds' and isinstance(x,list):
                        for j,ident in enumerate(x):
                            if ident not in {r['id'] for r in self.rows['projects']}:self.issue('error',q+[j],'missing_reference')
                    if k=='id' and (not isinstance(x,str) or not re.fullmatch(r'[A-Za-z0-9_-]{1,64}',x)):
                        self.issue('error',q,'invalid_id')
                    if k in ('currency','currencyTo') and x and x not in currencies:self.issue('error',q,'invalid_currency')
                    if k in ('date','dueDate','payDate','startDate','nextRenewal','cancelDate') and x:
                        try:self.convert(x,'date')
                        except (ValueError,TypeError):self.issue('error',q,'invalid_date')
                    if k in ('amount','amountTo','price','unitPrice','discount','refunded','commission','tax','cost') and x is not None:
                        try:self.validate_pending_amount(x,q,k)
                        except (ValueError,TypeError,InvalidOperation):self.issue('error',q,'invalid_money')
                    if k=='rate' and x is not None:
                        try:
                            if p==['settings','hourly']:self.validate_pending_amount(x,q,'hourly_rate')
                            elif isinstance(x,bool) or Decimal(str(x))<=0 or not Decimal(str(x)).is_finite():raise ValueError()
                        except (ValueError,TypeError,InvalidOperation):self.issue('error',q,'invalid_rate')
                    walk(x,q)
        pending_keys = PENDING - (set(ACTIVITY_TABLES) - {'subscription_events'}) if self.activity else PENDING
        # subscription_events are represented only when nested under subscriptions;
        # the generic root ``events`` collection remains outside migration 012.
        for key in sorted(pending_keys):walk(s.get(key,[]),[key])
        settings=s.get('settings',{})
        if isinstance(settings.get('hourly'),dict):walk(settings['hourly'],['settings','hourly'])
        alerts=settings.get('alerts',{})
        if isinstance(alerts,dict):
            for key,value in alerts.items():
                if key in ('upcomingDays','noSalesDays','dropPct') and (type(value) not in (int,Decimal) or value<=0 or key=='dropPct' and value>100):
                    self.issue('error',['settings','alerts',key],'invalid_alert_threshold')

    def validate_pending_amount(self,value,path,key):
        return self.convert(value,'money')

    def report(self):
        return {'format':'original-v1-partial','source_sha256':self.raw_hash,'environment':self.environment,
                'read_only':True,'complete_migration':False,'can_import':not any(i['level'] in ('error','review') for i in self.issues),
                'policy':'Errors or review findings reject the whole import; no record is pruned to break dependencies. Original remains authoritative for pending fields.',
                'collections':self.counts,'sql_counts':{t:len(v) for t,v in self.rows.items()},'issues':self.issues,
                'normalizations':self.normalizations,'pending_domain_validation':sorted(PENDING)}

    def plan(self):
        return {'environment':self.environment,'source_sha256':self.raw_hash,'rows':self.rows,'mapping':self.mapping,
                'pending':[i for i in self.issues if i['level']=='pending']}


def projection(mapping, rows):
    """Reconstruct only supported source paths, exclusively from actual SQL values."""
    by={t:{r['id']:r for r in records} for t,records in rows.items()}
    root={}
    def assign(path,value):
        nonlocal root
        if not path:root=value;return
        at=root
        for j,k in enumerate(path):
            if isinstance(at,list):
                while len(at)<=k:at.append(None)
            if j==len(path)-1:at[k]=value;return
            if isinstance(at,dict) and k not in at or isinstance(at,list) and at[k] is None:
                at[k]=[] if isinstance(path[j+1],int) else {}
            at=at[k]
    for m in mapping:
        if 'container' in m:assign(m['path'],[] if m['container']=='array' else {})
        else:
            v=by[m['table']][m['id']][m['column']]
            kind=m['kind'].removeprefix('nullable_')
            if v is not None and kind in ('integer','boolean'):v=int(v) if kind=='integer' else bool(int(v))
            if v is not None and kind=='timestamp':v=str(v)
            if v is not None and kind=='json':
                try:v=json.loads(v)
                except (TypeError,ValueError): pass
            assign(m['path'],v)
    return root
