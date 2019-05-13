/*
 *  Copyright (c) 2009 Facebook
 *  Copyright (c) 2014-2016 Qafoo GmbH
 *  Copyright (c) 2016-2017 Tideways GmbH
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 *
 */

#ifndef PHP_TIDEWAYS_H
#define PHP_TIDEWAYS_H

//（young5218）extern表明tideways_module_entry在其他模块
extern zend_module_entry tideways_module_entry;
#define phpext_tideways_ptr &tideways_module_entry

#ifdef PHP_WIN32
#define PHP_TIDEWAYS_API __declspec(dllexport)
#else
#define PHP_TIDEWAYS_API
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

/* Tideways version                           */
#define TIDEWAYS_VERSION       "4.1.7"

/* Fictitious function name to represent top of the call tree. The paranthesis
 * in the name is to ensure we don't conflict with user function names.  */
//虚函数名称代表调用树的顶部, 名称中的括号是为了确保我们不与用户函数名冲突。
#define ROOT_SYMBOL                "main()"

/* Size of a temp scratch buffer            */
#define SCRATCH_BUF_LEN            512

/* Hierarchical profiling flags.
 *
 * Note: Function call counts and wall (elapsed) time are always profiled.
 * The following optional flags can be used to control other aspects of
 * profiling.
 */
//（young5218）定义常量，在PHP_MINIT_FUNCTION中将其加入到全局常量列表中，php文件中可用
#define TIDEWAYS_FLAGS_NO_BUILTINS   0x0001 /* do not profile builtins */
#define TIDEWAYS_FLAGS_CPU           0x0002 /* gather CPU times for funcs */
#define TIDEWAYS_FLAGS_MEMORY        0x0004 /* gather memory usage for funcs */
#define TIDEWAYS_FLAGS_NO_USERLAND   0x0008 /* do not profile userland functions */
#define TIDEWAYS_FLAGS_NO_COMPILE    0x0010 /* do not profile require/include/eval */
#define TIDEWAYS_FLAGS_NO_SPANS      0x0020
#define TIDEWAYS_FLAGS_NO_HIERACHICAL 0x0040

#define TIDEWAYS_ANNOTATION_MAX_LENGTH 2000

/* Constant for ignoring functions, transparent to hierarchical profile */
#define TIDEWAYS_MAX_FILTERED_FUNCTIONS  256
#define TIDEWAYS_FILTERED_FUNCTION_SIZE                           \
               ((TIDEWAYS_MAX_FILTERED_FUNCTIONS + 7)/8)
#define TIDEWAYS_MAX_ARGUMENT_LEN 256

//（young5218）为数据类型定义别名
#if !defined(uint64)
typedef unsigned long long uint64;
#endif
#if !defined(uint32)
typedef unsigned int uint32;
#endif
#if !defined(uint8)
typedef unsigned char uint8;
#endif

#if PHP_VERSION_ID < 70000
struct _zend_string {
  char *val;
  int   len;
  int   persistent;
};
typedef struct _zend_string zend_string;
typedef long zend_long;
typedef int strsize_t;
typedef zend_uint uint32_t;
#endif

/**
 * *****************************
 * GLOBAL DATATYPES AND TYPEDEFS
 * *****************************
 */

/* Tideways maintains a stack of entries being profiled. The memory for the entry
 * is passed by the layer that invokes BEGIN_PROFILING(), e.g. the hp_execute()
 * function. Often, this is just C-stack memory.
 *
 * This structure is a convenient place to track start time of a particular
 * profile operation, recursion depth, and the name of the function being
 * profiled. */

//（young5218）定义数据存储结构
typedef struct hp_entry_t {
    char                   *name_hprof;                       /* function name 方法名*/
    int                     rlvl_hprof;        /* recursion level for function 函数递归级别*/
    uint64                  tsc_start;         /* start value for wall clock timer 系统时间*/
    uint64                  cpu_start;         /* start value for CPU clock timer CPU时间*/
    long int                mu_start_hprof;                    /* memory usage 内存占用*/
    long int                pmu_start_hprof;              /* peak memory usage 内存峰值*/
    struct hp_entry_t      *prev_hprof;    /* ptr to prev entry being profiled 前一个被探查的方法指针*/
    uint8                   hash_code;     /* hash_code for the function name  方法名的hash值*/
    long int                span_id; /* span id of this entry if any, otherwise -1  span id值*/
} hp_entry_t;

//（young5218）方法名称map
typedef struct hp_function_map {
    char **names;
    uint8 filter[TIDEWAYS_FILTERED_FUNCTION_SIZE];
} hp_function_map;

typedef struct tw_watch_callback {
    zend_fcall_info fci;
    zend_fcall_info_cache fcic;
} tw_watch_callback;

/* Tideways's global state.
 *
 * This structure is instantiated once.  Initialize defaults for attributes in
 * hp_init_profiler_state() Cleanup/free attributes in
 * hp_clean_profiler_state() */

//（young5218）声明全局变量
ZEND_BEGIN_MODULE_GLOBALS(hp)

    /*       ----------   Global attributes:  -----------       */

    /* Indicates if Tideways is currently enabled  是否启用tideways*/
    int              enabled;

    /* Indicates if Tideways was ever enabled during this request 在此请求期间是否曾启用过Tideways*/
    int              ever_enabled;

    int              prepend_overwritten;

    /* Holds all the Tideways statistics 保存所有Tideways统计数据*/
#if PHP_VERSION_ID >= 70000
    zval            stats_count;
    zval            spans;
    zval            exception;
#else
    zval            *stats_count;
    zval            *spans;
    zval            *exception;
#endif
    long            current_span_id;
    uint64          start_time;

    zval            *backtrace;

    /* Top of the profile stack 探针堆栈栈顶*/
    hp_entry_t      *entries;

    /* freelist of hp_entry_t chunks for reuse... hp_entry_t块可以重用的空闲列表*/
    hp_entry_t      *entry_free_list;

    /* Function that determines the transaction name and callback 确定事务名称和回调的函数*/
    zend_string       *transaction_function;
    zend_string     *transaction_name;
    char            *root;

    zend_string     *exception_function;

    double timebase_factor;

    /* Tideways flags */
    uint32 tideways_flags;

    /* counter table indexed by hash value of function names. 函数名哈希值的索引表*/
    uint8  func_hash_counters[256];

    /* Table of filtered function names and their filter 已过滤的函数名称及其过滤器表*/
    int     filtered_type; // 1 = blacklist, 2 = whitelist, 0 = nothing

    hp_function_map *filtered_functions;

    HashTable *trace_watch_callbacks;
    HashTable *trace_callbacks;
    HashTable *span_cache;	//如果spans数量达到上限，span_cache保存每个类别（category）对应的汇聚span的spanid

    uint32_t gc_runs; /* number of garbage collection runs 垃圾回收次数*/
    uint32_t gc_collected; /* number of collected items in garbage run 垃圾回收中收集的物品数量*/
    int compile_count;
    double compile_wt;
    uint64 cpu_start;
    int max_spans;

    int stack_threshold; /*堆栈阈值*/
ZEND_END_MODULE_GLOBALS(hp)

#ifdef ZTS
#define TWG(v) TSRMG(hp_globals_id, zend_hp_globals *, v)
#else
#define TWG(v) (hp_globals.v)
#endif

PHP_MINIT_FUNCTION(tideways);   	/*模块初始化*/
PHP_MSHUTDOWN_FUNCTION(tideways); 	/*模块关闭时*/
PHP_RINIT_FUNCTION(tideways);		/*请求开始前*/
PHP_RSHUTDOWN_FUNCTION(tideways); 	/*请求结束时*/
PHP_MINFO_FUNCTION(tideways);		/*设置php_info展示的扩展信息*/
PHP_GINIT_FUNCTION(hp);				/* 初始化全局变量时*/
PHP_GSHUTDOWN_FUNCTION(hp);			/*释放全局变量时*/

/*声明导出函数，PHP脚本可直接调用*/

/*启动探针采集，初始化探针的全局变量（系统时间、CPU时间等），创建span和hp_entry_t并填充字段*/
PHP_FUNCTION(tideways_enable);
PHP_FUNCTION(tideways_disable);
/*返回TWG(transaction_name)的副本*/
PHP_FUNCTION(tideways_transaction_name);
/*返回zval型的TWG(backtrace)*/
PHP_FUNCTION(tideways_fatal_backtrace);
/*返回bool型的TWG(prepend_overwritten)，表示auto_prepend_file是否被重写为Tideways.php*/
PHP_FUNCTION(tideways_prepend_overwritten);
//返回zval型的TWG(exception)
PHP_FUNCTION(tideways_last_detected_exception);
//将error信息的各个字段放入数组，并返回
PHP_FUNCTION(tideways_last_fatal_error);
//返回sql信息，tideways中未对该方法实现
PHP_FUNCTION(tideways_sql_minify);

//根据参数category，创建span，并加入到全局变量spans中
PHP_FUNCTION(tideways_span_create);
//返回zval型的TWG(spans)
PHP_FUNCTION(tideways_get_spans);

//为span.b(starts)添加cycle_timer() - TWG(start_time)
PHP_FUNCTION(tideways_span_timer_start);
//为span.e(stops)添加cycle_timer() - TWG(start_time)
PHP_FUNCTION(tideways_span_timer_stop);
//为span添加annotations注解，参数&annotations为zval型数据（数组）
PHP_FUNCTION(tideways_span_annotate);
//根据传参2“category类别”，将对应的tw_trace_callback设置到trace_callbacks，其key值为传参1“fun方法名”
//为方法（fun）找到对应的tw_trace_callback，设置进trace_callbacks
PHP_FUNCTION(tideways_span_watch);
//
PHP_FUNCTION(tideways_span_callback);

#endif  /* PHP_TIDEWAYS_H */
