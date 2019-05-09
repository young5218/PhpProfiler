#include "php.h"
#include "../php_tideways.h"
#include "../spans.h"

extern ZEND_DECLARE_MODULE_GLOBALS(hp)

//创建span，其中category为span类别
long tw_span_create(char *category, size_t category_len TSRMLS_DC)
{
    zval span, starts, stops;
    int idx;
    long parent = 0;
    //全局变量spans必须为数组
    if (Z_TYPE(TWG(spans)) != IS_ARRAY) {
        return -1;
    }
    //spans数组元素个数
    idx = zend_hash_num_elements(Z_ARRVAL(TWG(spans)));

    // If the max spans limit is reached, then we aggregate results on a single
    // span per category and mark it as "truncated" such that user interfaces
    // can detect these kind of spans and give them a proper name.
    //如果达到span数量上限，我们将每个类别(category)的结果汇总在一个span上，并将其标识为“truncated”，以便用户能检测这类汇聚后的span
    if (idx >= TWG(max_spans)) {
        zval *zv;

        if (zv = zend_hash_str_find(TWG(span_cache), category, category_len)) {
            idx = Z_LVAL_P(zv);

            if (idx > -1) {
            	//在全局变量spans中找到idx对应的span，在其注解中设置trunc=1
                tw_span_annotate_long(idx, "trunc", 1 TSRMLS_CC);
                //直接将之前的span_cache中记录类别的spanid返回，不再创建新的span
                return idx;
            }
        }
    }

    //对数组进行初始化
    array_init(&span);
    array_init(&starts);
    array_init(&stops);

    //在span数组中添加key和value
    add_assoc_stringl(&span, "n", category, category_len);
    add_assoc_zval(&span, "b", &starts);
    add_assoc_zval(&span, "e", &stops);

    if (parent > 0) {
        add_assoc_long(&span, "p", parent);
    }

    //更新spans索引表，在idx位置添加刚创建的span
    zend_hash_index_update(Z_ARRVAL(TWG(spans)), idx, &span);

    if (idx >= TWG(max_spans)) {
        zval zv;

        ZVAL_LONG(&zv, idx);//将zv赋值为long型的idx
        //在span_cache中把类型category对应的值更新为刚生成spanid(idx)
        zend_hash_str_update(TWG(span_cache), category, category_len, &zv);
    }

    return idx;
}


static int tw_convert_to_string(zval *zv)
{
    convert_to_string_ex(zv);

    return ZEND_HASH_APPLY_KEEP;
}

//在spans中找到spanId对应的span，将annotations中的键值添加到span.a中
void tw_span_annotate(long spanId, zval *annotations TSRMLS_DC)
{
    zval *span, *span_annotations, span_annotations_value, *zv;
    zend_string *key, *annotation_value;
    ulong num_key;

    if (spanId == -1) {
        return;
    }

    //获取spanId对应的span
    span = zend_hash_index_find(Z_ARRVAL(TWG(spans)), spanId);

    if (span == NULL) {
        return;
    }

    //获取span对应的span_annotations
    span_annotations = zend_hash_str_find(Z_ARRVAL_P(span), "a", sizeof("a") - 1);

    if (span_annotations == NULL) {
        span_annotations = &span_annotations_value;
        array_init(span_annotations);
        add_assoc_zval(span, "a", span_annotations);
    }

    //ZEND_HASH_FOREACH_KEY_VAL用于遍历数组（num_key为自然索引值，key为map的键，zv为指向值的zval
    ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(annotations), num_key, key, zv) {
        if (key) {
            annotation_value = zval_get_string(zv);
            add_assoc_str_ex(span_annotations, ZSTR_VAL(key), ZSTR_LEN(key), annotation_value);
        }
    } ZEND_HASH_FOREACH_END();
}

//tw_span_annotate_long(idx, "trunc", 1 TSRMLS_CC);
//根据spanId，在全局变量spans中找到对应的span，并将key和value以字符串形式，存储进span.a中
void tw_span_annotate_long(long spanId, char *key, long value)
{
    zval *span, *span_annotations, span_annotations_value;
    zval annotation_value;

    if (spanId == -1) {
        return;
    }

    //获取对应span
    span = zend_hash_index_find(Z_ARRVAL(TWG(spans)), spanId);

    if (span == NULL) {
        return;
    }

    //获取span的“a”(annotations数组)
    span_annotations = zend_hash_str_find(Z_ARRVAL_P(span), "a", sizeof("a") - 1);

    //如果annotations不存在，则初始化span_annotations，并加入到span中
    if (span_annotations == NULL) {
        span_annotations = &span_annotations_value;
        array_init(span_annotations);
        add_assoc_zval(span, "a", span_annotations);
    }

    ZVAL_LONG(&annotation_value, value);//将参数value的值，以long型赋值给annotation_value
    convert_to_string_ex(&annotation_value);//强制转换为string

    add_assoc_zval_ex(span_annotations, key, strlen(key), &annotation_value);//添加到span_annotations
}

//根据spanId，在全局变量spans中找到对应的span，并将key和value存储进span.a中
void tw_span_annotate_string(long spanId, char *key, char *value, int copy)
{
    zval *span, *span_annotations, span_annotations_value;
    int key_len, value_len;
    zend_string *value_trunc;

    if (spanId == -1) {
        return;
    }

    span = zend_hash_index_find(Z_ARRVAL(TWG(spans)), spanId);

    if (span == NULL) {
        return;
    }

    span_annotations = zend_hash_str_find(Z_ARRVAL_P(span), "a", sizeof("a") - 1);

    if (span_annotations == NULL) {
        span_annotations = &span_annotations_value;
        array_init(span_annotations);
        add_assoc_zval(span, "a", span_annotations);
    }

    key_len = strlen(key);
    value_len = strlen(value);

    if (value_len < TIDEWAYS_ANNOTATION_MAX_LENGTH) {
        add_assoc_string_ex(span_annotations, key, key_len, value);
    } else {
    	//如果value值过长，截取前TIDEWAYS_ANNOTATION_MAX_LENGTH个字符
        value_trunc = zend_string_init(value, TIDEWAYS_ANNOTATION_MAX_LENGTH, 0);
        add_assoc_str_ex(span_annotations, key, key_len, value_trunc);
    }
}
