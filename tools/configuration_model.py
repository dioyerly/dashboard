"""Block-4 projection. Exact decimals and explicit, narrowly scoped DEMO repair.

Never turns a commercial event into cash or rewrites the original byte archive.
"""
from decimal import Decimal, localcontext
from zoneinfo import available_timezones
from backup_model import Model, TABLES, REFS, PENDING

EXTRA_TABLES=['dataset_settings','exchange_rates']
EXTRA_REFS={'dataset_settings':{'hourly_currency':'currencies'},
            'exchange_rates':{'currency_id':'currencies','base_currency_id':'currencies'}}
DEMO_COSTS={'rs_c':('10659.179999999998','10659.18'),
            'rs_b':('12.299999999999999','12.30'),'rs_s':('4919.179999999999','4919.18')}


def exact(value,scale=18,whole=20,positive=False):
    import re
    if isinstance(value,bool) or not isinstance(value,(str,int,Decimal)):
        raise ValueError('exact_decimal_required')
    # Numeric JSON exponents are exact Decimal values; expand without float.
    # Exponent strings remain unsupported text requiring explicit review.
    text=format(value,'f') if isinstance(value,Decimal) else str(value)
    if not re.fullmatch(r'(0|[1-9][0-9]{0,'+str(whole-1)+r'})(\.[0-9]{1,'+str(scale)+r'})?',text):
        raise ValueError('precision_requires_review')
    d=Decimal(text)
    if positive and d<=0:raise ValueError('positive_required')
    with localcontext() as ctx:
        ctx.prec=80
        return format(d,'.'+str(scale)+'f')


class ConfigurationModel(Model):
    def __init__(self,raw,environment,demo_costs=False):
        self.demo_costs=demo_costs
        super().__init__(raw,environment)

    def obj(self,value,path,allowed):
        if path in ([],['settings']):allowed=set(allowed)|{'timezone'}
        return super().obj(value,path,allowed)

    def scalar(self,table,row,obj,key,path,column=None,kind='text',required=False,default=None):
        if table=='customers' and key in ('consent','origin') or table=='plans' and key=='trialDays':
            if key in obj and obj[key]=='':self.issue('review',path+[key],'empty_value_is_not_historical_null')
            kind='nullable_'+kind.removeprefix('nullable_');default=None
        return super().scalar(table,row,obj,key,path,column,kind,required,default)

    @staticmethod
    def convert(value,kind):
        if kind.startswith('nullable_'):
            if value is None or value=='':return None
            kind=kind[9:]
        if kind in ('precise','positive_precise'):return exact(value,positive=kind=='positive_precise')
        if kind=='timezone':
            if not isinstance(value,str) or value not in available_timezones():raise ValueError('invalid_timezone')
            return value
        return Model.convert(value,kind)

    def validate_rows(self):
        super().validate_rows()
        null_origins={r['id'] for r in self.rows['customers'] if r['origin'] is None}
        self.issues=[i for i in self.issues if not (i['code']=='invalid_enum' and len(i['path'])==3 and i['path'][0]=='customers' and i['path'][1] in null_origins and i['path'][2]=='origin')]

    def validate_pending_amount(self,value,path,key):
        # Descriptive infrastructure costs, quoted unit prices and hourly rates
        # can have real precision. Never blanket-round them to cash cents.
        if key in ('cost','unitPrice','hourly_rate'):
            return exact(value)
        return super().validate_pending_amount(value,path,key)

    def build(self):
        if not isinstance(self.source,dict) or not isinstance(self.source.get('settings'),dict):
            self.issue('error',[],'backup_object_and_settings_required');return
        repairs=[]
        if self.demo_costs:
            source=self.source
            if not isinstance(source,dict) or source.get('settings',{}).get('business',{}).get('name')!='Centro de Operaciones · DEMO':
                self.issue('error',[],'known_demo_provenance_required');return
            found=set()
            for index,r in enumerate(source.get('resources',[])):
                if isinstance(r,dict) and r.get('id') in DEMO_COSTS:
                    before,after=DEMO_COSTS[r['id']]
                    hist=r.get('priceHistory',[])
                    if not hist or str(hist[0].get('cost'))!=before:
                        self.issue('error',['resources',index],'known_demo_value_mismatch');return
                    repairs.append({'path':['resources',index,'priceHistory',0,'cost'],'rule':'authorized_known_demo_cost_times_0_82',
                                    'before':before,'after':after,'source_sha256':self.raw_hash})
                    hist[0]['cost']=Decimal(after);found.add(r['id'])
            if found!=set(DEMO_COSTS):self.issue('error',[],'known_demo_records_missing');return
        super().build()
        # A known binary artifact is never silently accepted as meaningful economics.
        if not self.demo_costs:
            for index,r in enumerate(self.source.get('resources',[])):
                if isinstance(r,dict) and r.get('id') in DEMO_COSTS and r.get('priceHistory'):
                    if str(r['priceHistory'][0].get('cost'))==DEMO_COSTS[r['id']][0]:
                        self.issue('review',['resources',index,'priceHistory',0,'cost'],'explicit_demo_normalization_required')
        self.normalizations.extend(repairs)
        # These fields become mapped below; remove only their former 'pending' findings.
        roots={'version','setupDone','createdAt','timezone','rates'}
        def obsolete(i):
            p=i['path']
            if i['code']=='absence_not_representable_in_current_schema':return True
            if i['code']=='invalid_alert_threshold':return True
            if p in (['setupDone'],['createdAt']) and self.source.get(p[0]) is None and i['code'] in ('invalid_boolean','invalid_timestamp'):return True
            if i['level']=='pending' and p and (p[0] in roots or p[:2] in (['settings','alerts'],['settings','hourly'])):return True
            return False
        self.issues=[i for i in self.issues if not obsolete(i)]
        for table in EXTRA_TABLES:self.rows[table]=[]
        source=self.source;settings=source.get('settings',{})
        row=self.add('dataset_settings','main',{'archived_at':None},[])
        for key,column,kind in [('version','schema_version','integer'),('setupDone','setup_done','nullable_boolean'),
                                ('createdAt','source_created_at','nullable_timestamp')]:
            self.scalar('dataset_settings',row,source,key,[],column,kind,default=None)
        timekeys=[(source,[]),(settings,['settings'])]
        supplied_zones=[obj['timezone'] for obj,_ in timekeys if 'timezone' in obj]
        if len(supplied_zones)==2 and supplied_zones[0]!=supplied_zones[1]:self.issue('error',['timezone'],'conflicting_timezone')
        row['timezone']=None
        for obj,path in timekeys:
            if 'timezone' in obj:
                if row['timezone'] is not None and row['timezone']!=obj['timezone']:
                    self.issue('error',path+['timezone'],'conflicting_timezone')
                self.scalar('dataset_settings',row,obj,'timezone',path,'timezone','nullable_timezone')
        for key in ('upcomingDays','noSalesDays','dropPct'):
            column={'upcomingDays':'upcoming_days','noSalesDays':'no_sales_days','dropPct':'drop_pct'}[key]
            self.scalar('dataset_settings',row,settings.get('alerts',{}),key,['settings','alerts'],column,'nullable_positive_precise')
        if row['drop_pct'] is not None and Decimal(row['drop_pct'])>100:self.issue('error',['settings','alerts','dropPct'],'invalid_alert_threshold')
        for key,col,kind in [('rate','hourly_rate','nullable_precise'),('currency','hourly_currency','nullable_currency')]:
            self.scalar('dataset_settings',row,settings.get('hourly',{}),key,['settings','hourly'],col,kind)
        # Preserve empty objects as well as absent optional fields.
        for key in ('alerts','hourly'):
            if key in settings:self.mapping.insert(2,{'path':['settings',key],'container':'object'})
        if 'rates' in source:self.container(['rates'],'array')
        seen=set()
        for i,r in enumerate(source.get('rates',[])):
            path=['rates',i]
            rate=self.record('exchange_rates',r,path,{'currency':('currency_id','currency',True,None),
                 'date':('business_date','date',True,None),'rate':('rate','positive_precise',True,None),
                 'source':('source','nullable_short',False,None)},defaults={'base_currency_id':settings.get('baseCurrency')})
            if not rate:continue
            key=(rate.get('currency_id'),rate.get('business_date'))
            if key in seen:self.issue('error',path,'duplicate_rate_date')
            seen.add(key)
            if not settings.get('baseCurrency'):self.issue('error',path,'rate_base_unknown')
        by={t:{r['id']:r for r in rows} for t,rows in self.rows.items()}
        for table,refs in EXTRA_REFS.items():
            for r in self.rows[table]:
                for column,parent in refs.items():
                    if r.get(column) is not None and r[column] not in by[parent]:self.issue('error',[table,r['id'],column],'missing_reference')

    def plan(self):
        plan=super().plan();plan['profile']='block4';plan['precision_evidence']=self.normalizations
        return plan

    def report(self):
        report=super().report();report['profile']='block4'
        report['pending_domain_validation']=sorted(PENDING-{'rates'})
        report['precision_policy']={'exact_sql':'DECIMAL(38,18), no rounding','cash_existing':'DECIMAL(20,2), reject overprecision',
                                    'demo_costs':'explicit known-demo flag only; original bytes retained; resources still pending SQL'}
        return report
